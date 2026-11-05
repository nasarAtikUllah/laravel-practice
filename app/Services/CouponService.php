<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Enums\ProductStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /** 20,000,000.00. Keeps all the integer maths far from overflow. */
    public const MAX_CART_CENTS = 2_000_000_000;

    public static function reservationMinutes(): int
    {
        $minutes = (int) config('coupons.reservation_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }

    // ---------- Quote: shows the discount, uses nothing ----------

    /**
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array<string, mixed>
     */
    public function quote(string $code, array $items, User $user): array
    {
        $coupon = $this->findByCode($code) ?? throw CouponException::invalid();

        $this->assertUsable($coupon, $user);

        $cart = $this->priceCart($items);
        $discount = $this->discountFor($coupon, $cart['total']);

        return $this->summary($coupon, $this->allocate($cart['lines'], $discount, $cart['total']), $cart['total'], $discount);
    }

    // ---------- Order flow ----------

    /** Pay now: the use is held until payment succeeds or the time runs out. */
    public function reserve(string $code, User $user, array $items, string $referenceType, int $referenceId, ?int $minutes = null): CouponRedemption
    {
        return $this->claim($code, $user, $items, $referenceType, $referenceId, RedemptionStatus::Reserved, $minutes);
    }

    /** Cash on delivery: the use is counted at once and stays until the order is cancelled. */
    public function redeem(string $code, User $user, array $items, string $referenceType, int $referenceId): CouponRedemption
    {
        return $this->claim($code, $user, $items, $referenceType, $referenceId, RedemptionStatus::Redeemed, null);
    }

    /** Payment succeeded. */
    public function commit(CouponRedemption $redemption): CouponRedemption
    {
        return DB::transaction(function () use ($redemption) {
            $locked = CouponRedemption::lockForUpdate()->findOrFail($redemption->id);

            if ($locked->status === RedemptionStatus::Redeemed) {
                return $locked;
            }

            // Only the cleanup job ends a hold, so a late payment still works until it has run.
            if ($locked->status !== RedemptionStatus::Reserved) {
                throw CouponException::useEnded();
            }

            $locked->update(['status' => RedemptionStatus::Redeemed, 'expires_at' => null]);

            return $locked;
        });
    }

    /** Payment failed or the order was cancelled. Works on held and on counted uses. */
    public function release(CouponRedemption $redemption, RedemptionStatus $as = RedemptionStatus::Released): bool
    {
        return DB::transaction(function () use ($redemption, $as) {
            $locked = CouponRedemption::lockForUpdate()->findOrFail($redemption->id);

            if (! in_array($locked->status, [RedemptionStatus::Reserved, RedemptionStatus::Redeemed], true)) {
                return false;
            }

            // The cleanup job may only end a hold that is still waiting.
            if ($as === RedemptionStatus::Expired && $locked->status !== RedemptionStatus::Reserved) {
                return false;
            }

            $locked->update(['status' => $as]);

            return true;
        });
    }

    /** Gives back the coupon use of a cancelled order. Safe to call twice. */
    public function releaseForOrder(string $referenceType, int $referenceId): int
    {
        $released = 0;

        $redemptions = CouponRedemption::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('status', RedemptionStatus::counting())
            ->get();

        foreach ($redemptions as $redemption) {
            if ($this->release($redemption)) {
                $released++;
            }
        }

        return $released;
    }

    /** Called by the scheduler every minute. */
    public function releaseExpired(int $limit = 200): int
    {
        $ids = CouponRedemption::query()
            ->where('status', RedemptionStatus::Reserved->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $released = 0;

        foreach ($ids as $id) {
            $redemption = CouponRedemption::find($id);

            if ($redemption && $this->release($redemption, RedemptionStatus::Expired)) {
                $released++;
            }
        }

        return $released;
    }

    // ---------- Rules ----------

    private function claim(string $code, User $user, array $items, string $referenceType, int $referenceId, RedemptionStatus $status, ?int $minutes): CouponRedemption
    {
        return DB::transaction(function () use ($code, $user, $items, $referenceType, $referenceId, $status, $minutes) {
            // The lock makes two checkouts wait for each other, so the limit cannot be passed.
            $coupon = Coupon::query()->where('code', $this->normalize($code))->lockForUpdate()->first()
                ?? throw CouponException::invalid();

            $existing = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            // A retry of the same order: nothing more to do.
            if ($existing && in_array($existing->status, [RedemptionStatus::Reserved, RedemptionStatus::Redeemed], true)) {
                return $existing;
            }

            $hasOtherCoupon = CouponRedemption::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('coupon_id', '!=', $coupon->id)
                ->whereIn('status', RedemptionStatus::counting())
                ->exists();

            if ($hasOtherCoupon) {
                throw CouponException::alreadyHasCoupon();
            }

            $this->assertUsable($coupon, $user);

            $cart = $this->priceCart($items);
            $discount = $this->discountFor($coupon, $cart['total']);

            $attributes = [
                'user_id' => $user->id,
                'discount_amount' => $this->fromCents($discount),
                'status' => $status,
                'expires_at' => $status === RedemptionStatus::Reserved
                    ? now()->addMinutes($minutes ?? self::reservationMinutes())
                    : null,
            ];

            if ($existing) {
                // The same order tries again after its coupon use ended.
                $existing->update($attributes);

                return $existing;
            }

            return CouponRedemption::create($attributes + [
                'coupon_id' => $coupon->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    private function assertUsable(Coupon $coupon, User $user): void
    {
        if (! $coupon->is_active || ! $coupon->isWithinWindow()) {
            throw CouponException::invalid();
        }

        if ($coupon->usage_limit_total !== null && $coupon->usedCount() >= $coupon->usage_limit_total) {
            throw CouponException::invalid();
        }

        if ($coupon->usage_limit_per_user !== null) {
            $mine = $coupon->redemptions()
                ->where('user_id', $user->id)
                ->whereIn('status', RedemptionStatus::counting())
                ->count();

            if ($mine >= $coupon->usage_limit_per_user) {
                throw CouponException::invalid();
            }
        }
    }

    private function discountFor(Coupon $coupon, int $totalCents): int
    {
        $minimum = $coupon->min_order_amount !== null ? $this->toCents($coupon->min_order_amount) : 0;

        if ($minimum > 0 && $totalCents < $minimum) {
            throw CouponException::minimumNotMet($this->fromCents($minimum));
        }

        $valueCents = $this->toCents($coupon->value);

        if ($coupon->type === CouponType::Percentage) {
            // The value in cents is the percentage in basis points (12.50% = 1250). Half rounds up.
            $discount = intdiv($totalCents * $valueCents + 5000, 10000);

            if ($coupon->max_discount !== null) {
                $discount = min($discount, $this->toCents($coupon->max_discount));
            }
        } else {
            $discount = $valueCents;
        }

        $discount = min($discount, $totalCents);

        if ($discount < 1) {
            throw CouponException::noDiscount();
        }

        return $discount;
    }

    // ---------- Cart ----------

    /**
     * Prices always come from the database, never from the client.
     *
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array{lines: array<int, array<string, int>>, total: int}
     */
    private function priceCart(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }

        if ($quantities === []) {
            throw CouponException::itemsUnavailable();
        }

        // dd(array_keys($quantities), ProductVariant::findOrFail(array_keys($quantities)));
        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($quantities))
            ->whereHas('product', fn ($product) => $product
                ->where('status', ProductStatus::Published->value)
                ->whereHas('brand', fn ($brand) => $brand->where('is_active', true))
                ->whereHas('collection', fn ($collection) => $collection->where('is_active', true)))
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($quantities)) {
            throw CouponException::itemsUnavailable();
        }

        $lines = [];
        $total = 0;

        foreach ($quantities as $id => $quantity) {
            $unit = $this->toCents($variants[$id]->price);
            $line = $unit * $quantity;
            $total += $line;

            $lines[] = [
                'variant_id' => $id,
                'quantity' => $quantity,
                'unit_cents' => $unit,
                'line_cents' => $line,
                'discount_cents' => 0,
            ];
        }

        if ($total > self::MAX_CART_CENTS) {
            throw CouponException::cartTooLarge();
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * Splits the discount over the lines. Left-over cents go to the last lines first,
     * and never above a line's own price. The Orders module needs this split for refunds.
     *
     * @param  array<int, array<string, int>>  $lines
     * @return array<int, array<string, int>>
     */
    private function allocate(array $lines, int $discount, int $total): array
    {
        $allocated = 0;

        foreach ($lines as $i => $line) {
            $share = intdiv($discount * $line['line_cents'], $total);
            $lines[$i]['discount_cents'] = $share;
            $allocated += $share;
        }

        $remainder = $discount - $allocated;

        while ($remainder > 0) {
            for ($i = count($lines) - 1; $i >= 0 && $remainder > 0; $i--) {
                if ($lines[$i]['discount_cents'] < $lines[$i]['line_cents']) {
                    $lines[$i]['discount_cents']++;
                    $remainder--;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, int>>  $lines
     * @return array<string, mixed>
     */
    private function summary(Coupon $coupon, array $lines, int $total, int $discount): array
    {
        return [
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'value' => number_format((float) $coupon->value, 2, '.', ''),
            'items_total' => $this->fromCents($total),
            'discount' => $this->fromCents($discount),
            'total_after_discount' => $this->fromCents($total - $discount),
            'lines' => array_map(fn (array $line) => [
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $this->fromCents($line['unit_cents']),
                'line_total' => $this->fromCents($line['line_cents']),
                'discount' => $this->fromCents($line['discount_cents']),
                'line_payable' => $this->fromCents($line['line_cents'] - $line['discount_cents']),
            ], $lines),
        ];
    }

    // ---------- Helpers ----------

    private function findByCode(string $code): ?Coupon
    {
        return Coupon::query()->where('code', $this->normalize($code))->first();
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
