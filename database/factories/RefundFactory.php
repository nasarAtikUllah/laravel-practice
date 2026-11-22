<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'order_id' => fn (array $attributes) => Payment::findOrFail($attributes['payment_id'])->order_id,
            'reference' => 'R'.strtoupper(Str::random(29)),
            'amount' => 100,
            'status' => RefundStatus::Pending,
            'reason' => 'Customer refund',
            'attempts' => 0,
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }
}
