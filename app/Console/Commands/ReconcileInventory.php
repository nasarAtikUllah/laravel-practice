<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('inventory:reconcile')]
#[Description('Compare stock numbers with the movement ledger and the active reservations')]
class ReconcileInventory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stockProblems = [];
        $reservedProblems = [];

        ProductVariant::withTrashed()
            ->select(['id', 'sku', 'stock', 'reserved'])
            ->chunkById(500, function ($variants) use (&$stockProblems, &$reservedProblems) {
                $ids = $variants->pluck('id');

                $ledger = InventoryMovement::query()
                    ->whereIn('product_variant_id', $ids)
                    ->selectRaw('product_variant_id, SUM(quantity) as total')
                    ->groupBy('product_variant_id')
                    ->pluck('total', 'product_variant_id');

                $held = InventoryReservation::query()
                    ->where('status', ReservationStatus::Active->value)
                    ->whereIn('product_variant_id', $ids)
                    ->selectRaw('product_variant_id, SUM(quantity) as total')
                    ->groupBy('product_variant_id')
                    ->pluck('total', 'product_variant_id');

                foreach ($variants as $variant) {
                    $ledgerTotal = (int) ($ledger[$variant->id] ?? 0);
                    $heldTotal = (int) ($held[$variant->id] ?? 0);

                    if ($variant->stock !== $ledgerTotal) {
                        $stockProblems[] = [$variant->id, $variant->sku, $variant->stock, $ledgerTotal, $variant->stock - $ledgerTotal];
                    }

                    if ($variant->reserved !== $heldTotal) {
                        $reservedProblems[] = [$variant->id, $variant->sku, $variant->reserved, $heldTotal, $variant->reserved - $heldTotal];
                    }
                }
            });

        // Active holds that should have been released a while ago: the expiry job may have stopped.
        $stuck = InventoryReservation::query()
            ->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '<', now()->subMinutes(5))
            ->count();

        if ($stockProblems) {
            $this->error(count($stockProblems).' variant(s) where stock does not match the ledger.');
            $this->table(['Variant ID', 'SKU', 'Stock', 'Ledger total', 'Difference'], $stockProblems);
        }

        if ($reservedProblems) {
            $this->error(count($reservedProblems).' variant(s) where reserved does not match the active reservations.');
            $this->table(['Variant ID', 'SKU', 'Reserved', 'Active reservations', 'Difference'], $reservedProblems);
        }

        if ($stuck > 0) {
            $this->error("{$stuck} reservation(s) expired more than 5 minutes ago and are still active. Is the scheduler running?");
        }

        if (! $stockProblems && ! $reservedProblems && $stuck === 0) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        Log::error('Inventory reconcile found problems', [
            'stock_mismatches' => count($stockProblems),
            'reserved_mismatches' => count($reservedProblems),
            'stuck_reservations' => $stuck,
        ]);

        return self::FAILURE;
    }
}
