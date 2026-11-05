<?php

use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not let the database save two brands with the same name', function () {
    Brand::factory()->create([
        'name' => 'RFL',
        'slug' => 'rfl',
    ]);

    expect(fn () => Brand::factory()->create([
        'name' => 'RFL',
        'slug' => 'rfl-2',
    ]))->toThrow(QueryException::class);
});

it('does not let the database save two collections with the same name in one brand', function () {
    $brand = Brand::factory()->create();

    Collection::factory()->create([
        'brand_id' => $brand->id,
        'name' => 'Kitchen',
        'slug' => 'kitchen',
    ]);

    expect(fn () => Collection::factory()->create([
        'brand_id' => $brand->id,
        'name' => 'Kitchen',
        'slug' => 'kitchen-2',
    ]))->toThrow(QueryException::class);
});

it('allows the same collection name in different brands', function () {
    $brand1 = Brand::factory()->create();
    $brand2 = Brand::factory()->create();

    Collection::factory()->create([
        'brand_id' => $brand1->id,
        'name' => 'Kitchen',
        'slug' => 'kitchen',
    ]);

    Collection::factory()->create([
        'brand_id' => $brand2->id,
        'name' => 'Kitchen',
        'slug' => 'kitchen',
    ]);

    expect(Collection::where('name', 'Kitchen')->count())->toBe(2);
});
