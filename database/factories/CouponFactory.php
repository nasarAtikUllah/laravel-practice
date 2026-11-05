<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE-####')),
            'type' => CouponType::Percentage,
            'value' => 10,
            'max_discount' => null,
            'min_order_amount' => null,
            'starts_at' => null,
            'expires_at' => null,
            'usage_limit_total' => null,
            'usage_limit_per_user' => 1,
            'is_active' => true,
        ];
    }

    public function percentage(float $value = 10): static
    {
        return $this->state(['type' => CouponType::Percentage, 'value' => $value]);
    }

    public function fixed(float $amount = 100): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $amount, 'max_discount' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(['starts_at' => now()->subDays(10), 'expires_at' => now()->subDay()]);
    }

    public function scheduled(): static
    {
        return $this->state(['starts_at' => now()->addDay(), 'expires_at' => now()->addDays(10)]);
    }
}
