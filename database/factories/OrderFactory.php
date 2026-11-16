<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'ORD-'.now()->format('YY-m-d').'-'.fake()->unique()->numerify('######'),
            'user_id' => User::factory(),
            'status' => OrderStatus::PendingConfirmation,
            'payment_method' => PaymentMethod::Cod,
            'payment_status' => PaymentStatus::Unpaid,
            'currency' => 'BDT',
            'items_total' => 1000,
            'discount_total' => 0,
            'delivery_fee' => 60,
            'grand_total' => 1060,
            'shipping_name' => 'Rahim Uddin',
            'shipping_phone' => '01712345678',
            'shipping_address' => 'House 12, Road 5, Dhanmondi',
            'shipping_district' => 'Dhaka',
            'idempotency_key' => Str::random(20),
            'placed_at' => now(),
            'expires_at' => now()->addHours(24),
        ];
    }
}
