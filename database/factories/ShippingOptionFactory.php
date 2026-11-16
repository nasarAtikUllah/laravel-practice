<?php

namespace Database\Factories;

use App\Models\ShippingOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingOption>
 */
class ShippingOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'option-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'description' => null,
            'estimated_days' => '1-2 days',
            'fee' => 60,
            'min_order_amount' => null,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function asDefault(): static
    {
        return $this->state(['is_default' => true, 'min_order_amount' => null, 'is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function freeFrom(float $minimum): static
    {
        return $this->state(['fee' => 0, 'min_order_amount' => $minimum]);
    }
}
