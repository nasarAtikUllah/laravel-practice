<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CollectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brand = Brand::where('slug', 'rfl')->firstOrFail();

        foreach (['Kitchen', 'Bedroom', 'Home'] as $name) {
            Collection::updateOrCreate(
                ['brand_id' => $brand->id, 'slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
