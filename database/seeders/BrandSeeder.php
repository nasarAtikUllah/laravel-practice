<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // BrandSeeder
        foreach ([['RFL', 'rfl'], ['OTOBI', 'otobi']] as [$name, $slug]) {
            Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
