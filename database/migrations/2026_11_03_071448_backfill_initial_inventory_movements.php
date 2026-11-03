<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('product_variants')
            ->where('stock', '>', 0)
            ->chunkById(500, function ($variants) {
                $now = now();

                DB::table('inventory_movements')->insert(
                    $variants->map(fn ($variant) => [
                        'product_variant_id' => $variant->id,
                        'type' => 'initial',
                        'quantity' => $variant->stock,
                        'stock_before' => 0,
                        'stock_after' => $variant->stock,
                        'reason' => 'Backfill of existing stock',
                        'created_at' => $now,
                    ])->all()
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('inventory_movements')->where('reason', 'Backfill of existing stock')->delete();
    }
};
