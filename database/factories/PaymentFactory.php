<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->state([
                'status' => OrderStatus::PendingPayment,
                'payment_method' => PaymentMethod::Online,
                'payment_status' => PaymentStatus::Unpaid,
            ]),
            'provider' => 'sslcommerz',
            'reference' => 'P'.fake()->unique()->numberBetween(1, 9999999).'-'.strtoupper(Str::random(10)),
            'provider_reference' => null,
            'session_key' => 'SESSION-'.strtoupper(Str::random(12)),
            'amount' => 1060,
            'paid_amount' => null,
            'currency' => 'BDT',
            'status' => PaymentRecordStatus::Initiated,
            'checkout_url' => 'https://sandbox.sslcommerz.com/EasyCheckOut/'.Str::random(12),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function succeeded(): static
    {
        return $this->state([
            'status' => PaymentRecordStatus::Succeeded,
            'paid_amount' => 1060,
            'provider_reference' => 'BANK-'.strtoupper(Str::random(10)),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => PaymentRecordStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'The payment was not completed.',
        ]);
    }
}
