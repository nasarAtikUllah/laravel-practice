<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            // brand_id always follows the collection, so they can never disagree
            'collection_id' => Collection::factory(),
            'brand_id' => fn (array $attributes) => Collection::find($attributes['collection_id'])->brand_id,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(),
            'status' => ProductStatus::Published,
            'seo_title' => null,
            'seo_description' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    public function upcoming(): static
    {
        return $this->state(['status' => ProductStatus::Upcoming]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::Archived]);
    }
}
