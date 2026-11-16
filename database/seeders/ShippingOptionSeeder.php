<?php

namespace Database\Seeders;

use App\Models\ShippingOption;
use Illuminate\Database\Seeder;

class ShippingOptionSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            [
                'code' => 'inside-city',
                'name' => 'Inside city delivery',
                'description' => 'Delivery inside the city',
                'estimated_days' => '1-2 days',
                'fee' => 60,
                'min_order_amount' => null,
                'is_default' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'outside-city',
                'name' => 'Outside city delivery',
                'description' => 'Delivery outside the city',
                'estimated_days' => '2-3 days',
                'fee' => 120,
                'min_order_amount' => null,
                'is_default' => false,
                'sort_order' => 20,
            ],
            [
                'code' => 'free-delivery',
                'name' => 'Free delivery',
                'description' => 'Free delivery on big orders',
                'estimated_days' => '3-5 days',
                'fee' => 0,
                'min_order_amount' => (float) config('shop.free_delivery_min_order', 5000),
                'is_default' => false,
                'sort_order' => 30,
            ],
        ];

        foreach ($options as $option) {
            // Safe to run again in production: it never overwrites a fee the admin changed,
            // and withTrashed() means an option the admin deleted is not created again.
            ShippingOption::withTrashed()->firstOrCreate(['code' => $option['code']], $option);
        }
    }
}
