<?php

use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------- LIST ----------

it('lets guests list brands without a token', function () {
    Brand::factory()->count(2)->create();

    $this->getJson('/api/frontend/brands')
        ->assertOk()
        ->assertJsonPath('message', 'Brands retrieved successfully.')
        ->assertJsonCount(2, 'data');
});

it('shows only active brands in the list', function () {
    Brand::factory()->create(['name' => 'Visible', 'slug' => 'visible']);
    Brand::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden']);

    $this->getJson('/api/frontend/brands')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'visible')
        ->assertJsonPath('meta.total', 1);
});

it('does not expose private fields in the list', function () {
    Brand::factory()->create();

    $this->getJson('/api/frontend/brands')
        ->assertOk()
        ->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.created_at')
        ->assertJsonMissingPath('data.0.updated_at');
});

it('sorts brands by name', function () {
    Brand::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    Brand::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

    $this->getJson('/api/frontend/brands')
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.1.name', 'Zeta');
});

it('searches brands by name', function () {
    Brand::factory()->create(['name' => 'Walton', 'slug' => 'walton']);
    Brand::factory()->create(['name' => 'Singer', 'slug' => 'singer']);

    $this->getJson('/api/frontend/brands?search=walt')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Walton');
});

it('does not return an inactive brand even when searched by name', function () {
    Brand::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden']);

    $this->getJson('/api/frontend/brands?search=Hidden')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('paginates 12 brands per page', function () {
    Brand::factory()->count(15)->create();

    $this->getJson('/api/frontend/brands')
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 2);
});

it('counts only active collections in collections_count', function () {
    $brand = Brand::factory()->create();
    Collection::factory()->count(2)->create(['brand_id' => $brand->id]);
    Collection::factory()->create(['brand_id' => $brand->id, 'is_active' => false]);

    $this->getJson('/api/frontend/brands')
        ->assertJsonPath('data.0.collections_count', 2);
});

// ---------- DETAILS ----------

it('shows a brand by slug without a token', function () {
    $brand = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);

    $this->getJson('/api/frontend/brands/rfl')
        ->assertOk()
        ->assertJsonPath('message', 'Brand retrieved successfully.')
        ->assertJsonPath('data.id', $brand->id)
        ->assertJsonPath('data.slug', 'rfl');
});

it('includes only active collections in the brand details', function () {
    $brand = Brand::factory()->create(['slug' => 'rfl']);
    Collection::factory()->create(['brand_id' => $brand->id, 'name' => 'Kitchen', 'slug' => 'kitchen']);
    Collection::factory()->create(['brand_id' => $brand->id, 'name' => 'Old Range', 'slug' => 'old-range', 'is_active' => false]);

    $this->getJson('/api/frontend/brands/rfl')
        ->assertOk()
        ->assertJsonPath('data.collections_count', 1)
        ->assertJsonCount(1, 'data.collections')
        ->assertJsonPath('data.collections.0.slug', 'kitchen');
});

it('does not expose private fields in the brand details', function () {
    $brand = Brand::factory()->create(['slug' => 'rfl']);
    Collection::factory()->create(['brand_id' => $brand->id]);

    $this->getJson('/api/frontend/brands/rfl')
        ->assertOk()
        ->assertJsonMissingPath('data.is_active')
        ->assertJsonMissingPath('data.created_at')
        ->assertJsonMissingPath('data.collections.0.is_active')
        ->assertJsonMissingPath('data.collections.0.brand_id');
});

it('returns an empty collections list for a brand with no active collections', function () {
    Brand::factory()->create(['slug' => 'rfl']);

    $this->getJson('/api/frontend/brands/rfl')
        ->assertOk()
        ->assertJsonPath('data.collections', [])
        ->assertJsonPath('data.collections_count', 0);
});

it('returns 404 for an inactive brand', function () {
    Brand::factory()->inactive()->create(['slug' => 'hidden']);

    $this->getJson('/api/frontend/brands/hidden')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

it('returns 404 for an unknown slug', function () {
    $this->getJson('/api/frontend/brands/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});
