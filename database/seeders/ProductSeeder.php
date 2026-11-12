<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $collection = Collection::where('slug', 'kitchen')
            ->whereHas('brand', fn ($q) => $q->where('slug', 'rfl'))
            ->firstOrFail();

        $products = [
            'Non-Stick Frying Pan',
            'Stainless Steel Frying Pan',
            'Aluminium Saucepan',
            'Stainless Steel Saucepan',
            'Non-Stick Casserole',
            'Cooking Pot',
            'Pressure Cooker',
            'Rice Cooker',
            'Electric Kettle',
            'Water Bottle',
            'Lunch Box',
            'Food Storage Container',
            'Kitchen Knife Set',
            'Cutting Board',
            'Mixing Bowl',
            'Serving Bowl',
            'Dinner Plate Set',
            'Soup Bowl',
            'Glass Set',
            'Coffee Mug Set',
            'Tea Cup Set',
            'Cooking Spoon Set',
            'Spatula Set',
            'Kitchen Ladle',
            'Vegetable Peeler',
            'Potato Masher',
            'Can Opener',
            'Kitchen Scissors',
            'Grater',
            'Colander',
            'Measuring Cup Set',
            'Measuring Spoon Set',
            'Rolling Pin',
            'Baking Tray',
            'Cake Pan',
            'Oven Dish',
            'Tawa',
            'Wok Pan',
            'Steamer Pot',
            'Stock Pot',
            'Milk Pan',
            'Frying Basket',
            'Oil Dispenser',
            'Spice Container Set',
            'Kitchen Rack',
            'Dish Drying Rack',
            'Thermos Flask',
            'Vacuum Flask',
            'Serving Tray',
            'Kitchen Organizer',
        ];

        $inventoryService = app(InventoryService::class);

        foreach ($products as $index => $name) {
            $slug = Str::slug($name);

            $product = Product::updateOrCreate(
                ['slug' => 'rfl-'.$slug],
                [
                    'brand_id' => $collection->brand_id,
                    'collection_id' => $collection->id,
                    'name' => 'RFL '.$name,
                    'description' => 'High-quality RFL '.strtolower($name).'.',
                    'status' => ProductStatus::Published,
                ]
            );

            $basePrice = 800 + ($index * 50);

            $variants = [
                [
                    'sku' => 'RFL-'.strtoupper(Str::slug($name)).'-SM',
                    'title' => 'Small',
                    'price' => $basePrice,
                    'compare_price' => $basePrice + 250,
                    'stock' => 30,
                ],
                [
                    'sku' => 'RFL-'.strtoupper(Str::slug($name)).'-LG',
                    'title' => 'Large',
                    'price' => $basePrice + 300,
                    'compare_price' => $basePrice + 500,
                    'stock' => 20,
                ],
            ];

            foreach ($variants as $data) {
                $stock = $data['stock'];
                unset($data['stock']);

                $variant = ProductVariant::updateOrCreate(
                    ['sku' => $data['sku']],
                    $data + ['product_id' => $product->id]
                );

                if ($variant->wasRecentlyCreated) {
                    $inventoryService->initial($variant, $stock);
                }
            }
        }
    }
}
