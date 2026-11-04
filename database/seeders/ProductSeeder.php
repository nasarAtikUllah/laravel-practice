<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $collection = Collection::where('slug', 'kitchen')
            ->whereHas('brand', fn ($q) => $q->where('slug', 'rfl'))
            ->firstOrFail();

        $product = Product::updateOrCreate(
            ['slug' => 'rfl-non-stick-pan'],
            [
                'brand_id' => $collection->brand_id,
                'collection_id' => $collection->id,
                'name' => 'RFL Non-Stick Pan',
                'description' => 'Non-stick frying pan.',
                'status' => ProductStatus::Published,
            ]
        );

        foreach ([
            ['sku' => 'RFL-PAN-24', 'title' => '24 cm', 'price' => 1200, 'compare_price' => 1500, 'stock' => 30],
            ['sku' => 'RFL-PAN-28', 'title' => '28 cm', 'price' => 1500, 'compare_price' => null, 'stock' => 20],
        ] as $data) {
            $stock = $data['stock'];
            unset($data['stock']);

            $variant = ProductVariant::updateOrCreate(['sku' => $data['sku']], $data + ['product_id' => $product->id]);

            if ($variant->wasRecentlyCreated) {
                app(InventoryService::class)->initial($variant, $stock);
            }
        }

    }
}
