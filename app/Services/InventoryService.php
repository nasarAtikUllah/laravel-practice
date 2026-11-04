<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ReservationNotActiveException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    public const DEFAULT_RESERVATION_MINUTES = 15;

    public const DEFAULT_COD_AUTO_CANCEL_HOURS = 24;

    public static function reservationMinutes(): int
    {
        $minutes = (int) config('inventory.reservation_minutes', self::DEFAULT_RESERVATION_MINUTES);

        return $minutes > 0 ? $minutes : self::DEFAULT_RESERVATION_MINUTES;
    }

    public static function codAutoCancelHours(): int
    {
        $hours = (int) config('inventory.cod_auto_cancel_hours', self::DEFAULT_COD_AUTO_CANCEL_HOURS);

        return $hours > 0 ? $hours : self::DEFAULT_COD_AUTO_CANCEL_HOURS;
    }

    // ---------- Manual changes (admin) ----------

    /** For a variant created with stock 0: writes the first movement. */
    public function initial(ProductVariant $variant, int $quantity, ?User $by = null): ?InventoryMovement
    {
        if ($quantity === 0) {
            return null;
        }

        $this->assertPositive($quantity);

        return $this->apply($variant->id, StockMovementType::Initial, $quantity, 'Initial stock', $by);
    }

    public function restock(ProductVariant $variant, int $quantity, ?string $reason = null, ?User $by = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variant->id, StockMovementType::Restock, $quantity, $reason, $by);
    }

    public function damage(ProductVariant $variant, int $quantity, string $reason, ?User $by = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variant->id, StockMovementType::Damage, -$quantity, $reason, $by);
    }

    /** Sets stock to the counted number. Returns null when nothing changed. */
    public function countTo(ProductVariant $variant, int $counted, ?string $reason = null, ?User $by = null): ?InventoryMovement
    {
        if ($counted < 0) {
            throw new InvalidArgumentException('The counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($variant, $counted, $reason, $by) {
            $locked = $this->lock($variant->id);
            $difference = $counted - $locked->stock;

            if ($difference === 0) {
                return null;
            }

            return $this->apply($locked->id, StockMovementType::Correction, $difference, $reason ?? 'Stock count', $by);
        });
    }

    // ---------- Orders: cash on delivery ----------

    public function sell(ProductVariant $variant, int $quantity, string $referenceType, int $referenceId, ?User $by = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variant->id, StockMovementType::Sale, -$quantity, null, $by, $referenceType, $referenceId);
    }

    public function restoreFromCancellation(ProductVariant $variant, int $quantity, string $referenceType, int $referenceId, ?User $by = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variant->id, StockMovementType::Cancellation, $quantity, null, $by, $referenceType, $referenceId);
    }

    // ---------- Returns (after the item is received) ----------
    /**
     * Call this only after the return was approved and the item was received.
     * The approval lives in the Returns workflow; this method only makes sure
     * that an admin or inventory staff member confirmed the receipt.
     */
    public function restoreFromReturn(
        ProductVariant $variant,
        int $quantity,
        string $referenceType,
        int $referenceId,
        User $receivedBy,
        bool $sellable = true,
    ): InventoryMovement {
        if (! $receivedBy->canManageInventory()) {
            throw new AuthorizationException('Only an admin or inventory staff can confirm a received return.');
        }

        $this->assertPositive($quantity);

        return DB::transaction(function () use ($variant, $quantity, $referenceType, $referenceId, $receivedBy, $sellable) {
            $movement = $this->apply($variant->id, StockMovementType::Return, $quantity, null, $receivedBy, $referenceType, $referenceId);

            if (! $sellable) {
                // Net stock stays the same. The ledger shows both steps.
                $this->apply($variant->id, StockMovementType::Damage, -$quantity, 'Returned item is not sellable', $receivedBy, $referenceType, $referenceId);
            }

            return $movement;
        });
    }

    // ---------- Orders: pay now (hold, then commit or release) ----------

    public function reserve(ProductVariant $variant, int $quantity, string $referenceType, int $referenceId, ?int $minutes = null): InventoryReservation
    {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($variant, $quantity, $referenceType, $referenceId, $minutes) {
            $locked = $this->lock($variant->id);

            if ($locked->trashed()) {
                throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$locked->id]);
            }

            $existing = InventoryReservation::query()
                ->where('product_variant_id', $locked->id)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            // A retry of the same order: nothing more to do.
            if ($existing && in_array($existing->status, [ReservationStatus::Active, ReservationStatus::Committed], true)) {
                return $existing;
            }

            $available = max(0, $locked->stock - $locked->reserved);

            if ($available < $quantity) {
                throw new InsufficientStockException($available, $quantity);
            }

            $locked->reserved += $quantity;
            $locked->save();

            $attributes = [
                'quantity' => $quantity,
                'status' => ReservationStatus::Active,
                'expires_at' => now()->addMinutes($minutes ?? self::reservationMinutes()),
            ];

            if ($existing) {
                // The same order tries again after its hold ended.
                $existing->update($attributes);

                return $existing;
            }

            return InventoryReservation::create($attributes + [
                'product_variant_id' => $locked->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    /** Payment succeeded: the held stock becomes a sale. */
    public function commitReservation(InventoryReservation $reservation, ?User $by = null): InventoryMovement
    {
        return DB::transaction(function () use ($reservation, $by) {
            $locked = InventoryReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === ReservationStatus::Committed) {
                return InventoryMovement::query()
                    ->where('product_variant_id', $locked->product_variant_id)
                    ->where('type', StockMovementType::Sale->value)
                    ->where('reference_type', $locked->reference_type)
                    ->where('reference_id', $locked->reference_id)
                    ->firstOrFail();
            }

            // Only the cleanup job expires a hold, so a late payment still
            // works until the job has run.
            if ($locked->status !== ReservationStatus::Active) {
                throw new ReservationNotActiveException;
            }

            $movement = $this->apply(
                $locked->product_variant_id,
                StockMovementType::Sale,
                -$locked->quantity,
                null,
                $by,
                $locked->reference_type,
                $locked->reference_id,
                releaseReserved: $locked->quantity,
            );

            $locked->update(['status' => ReservationStatus::Committed]);

            return $movement;
        });
    }

    /** Payment failed or the order was cancelled: give the held stock back. */
    public function releaseReservation(InventoryReservation $reservation, ReservationStatus $as = ReservationStatus::Released): bool
    {
        return DB::transaction(function () use ($reservation, $as) {
            $locked = InventoryReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Active) {
                return false;
            }

            $variant = $this->lock($locked->product_variant_id);
            $variant->reserved = max(0, $variant->reserved - $locked->quantity);
            $variant->save();

            $locked->update(['status' => $as]);

            return true;
        });
    }

    /** Called by the scheduler every minute. */
    public function releaseExpired(int $limit = 200): int
    {
        $ids = InventoryReservation::query()
            ->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $released = 0;

        foreach ($ids as $id) {
            $reservation = InventoryReservation::find($id);

            if ($reservation && $this->releaseReservation($reservation, ReservationStatus::Expired)) {
                $released++;
            }
        }

        return $released;
    }

    // ---------- The one place where stock changes ----------

    private function apply(
        int $variantId,
        StockMovementType $type,
        int $change,
        ?string $reason = null,
        ?User $by = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        int $releaseReserved = 0,
    ): InventoryMovement {
        if ($change === 0) {
            throw new InvalidArgumentException('The quantity cannot be 0.');
        }

        return DB::transaction(function () use ($variantId, $type, $change, $reason, $by, $referenceType, $referenceId, $releaseReserved) {
            $variant = $this->lock($variantId);

            // A deleted variant can receive stock back, but nothing can leave it.
            if ($change < 0 && $variant->trashed()) {
                throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
            }

            // Same reference + same type = a retry. Return the first result.
            if ($referenceType !== null && $referenceId !== null) {
                $existing = InventoryMovement::query()
                    ->where('product_variant_id', $variantId)
                    ->where('type', $type->value)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $before = $variant->stock;
            $after = $before + $change;
            $stillReserved = max(0, $variant->reserved - $releaseReserved);

            // Never below zero, and never below what pending orders hold.
            if ($after < 0 || $after < $stillReserved) {
                throw new InsufficientStockException(max(0, $before - $variant->reserved), abs($change));
            }

            $variant->stock = $after;
            $variant->reserved = $stillReserved;
            $variant->save();

            return InventoryMovement::create([
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity' => $change,
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $by?->id,
            ]);
        });
    }

    /**
     * Puts back the stock of every line that was sold for this order.
     * It reads the sale movements, so the caller does not need the order lines.
     * Safe to call twice: the second call changes nothing.
     *
     * @return int number of lines restored by this call
     */
    public function restoreStockForOrder(string $referenceType, int $referenceId, ?string $reason = null, ?User $by = null): int
    {
        return DB::transaction(function () use ($referenceType, $referenceId, $reason, $by) {
            $sales = InventoryMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->orderBy('product_variant_id') // same lock order every time, so no deadlocks
                ->get();

            $restored = 0;

            foreach ($sales as $sale) {
                $movement = $this->apply(
                    $sale->product_variant_id,
                    StockMovementType::Cancellation,
                    abs($sale->quantity),
                    $reason ?? 'Order cancelled',
                    $by,
                    $referenceType,
                    $referenceId,
                );

                if ($movement->wasRecentlyCreated) {
                    $restored++;
                }
            }

            return $restored;
        });
    }

    private function lock(int $variantId): ProductVariant
    {
        return ProductVariant::withTrashed()->lockForUpdate()->findOrFail($variantId);
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('The quantity must be at least 1.');
        }
    }
}
