<?php

use App\Models\Brand;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on every brand route', function () {
    $brand = Brand::factory()->create();

    $this->getJson('/api/brands')->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
    $this->postJson('/api/brands', ['name' => 'Walton'])->assertUnauthorized();
    $this->getJson("/api/brands/{$brand->id}")->assertUnauthorized();
    $this->putJson("/api/brands/{$brand->id}", ['name' => 'New'])->assertUnauthorized();
    $this->deleteJson("/api/brands/{$brand->id}")->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    // ---------- LIST ----------

    it('lists brands with pagination meta', function () {
        Brand::factory()->count(20)->create();

        $this->getJson('/api/brands')
            ->assertOk()
            ->assertJsonPath('message', 'Brands retrieved successfully.')
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('searches brands by name', function () {
        Brand::factory()->create(['name' => 'Walton', 'slug' => 'walton']);
        Brand::factory()->create(['name' => 'Singer', 'slug' => 'singer']);

        $this->getJson('/api/brands?search=walt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Walton');
    });

    // ---------- CREATE ----------

    it('creates a brand', function () {
        $this->postJson('/api/brands', [
            'name' => 'Walton',
            'description' => 'Electronics',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Brand created successfully.')
            ->assertJsonPath('data.name', 'Walton')
            ->assertJsonPath('data.slug', 'walton');

        $this->assertDatabaseHas('brands', ['name' => 'Walton', 'slug' => 'walton']);
    });

    it('cleans a custom slug', function () {
        $this->postJson('/api/brands', ['name' => 'Walton', 'slug' => 'My Custom Slug'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'my-custom-slug');
    });

    it('requires a name', function () {
        $this->postJson('/api/brands', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('rejects a duplicate name', function () {
        Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);

        $this->postJson('/api/brands', ['name' => 'RFL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        expect(Brand::count())->toBe(1);
    });

    it('rejects a duplicate slug', function () {
        Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);

        $this->postJson('/api/brands', ['name' => 'Other', 'slug' => 'rfl'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    });

    it('rejects a name that is too long', function () {
        $this->postJson('/api/brands', ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('rejects a non-boolean is_active', function () {
        $this->postJson('/api/brands', ['name' => 'Walton', 'is_active' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    });

    // ---------- SHOW ----------

    it('shows a brand with its collections count', function () {
        $brand = Brand::factory()->create();
        Collection::factory()->count(3)->create(['brand_id' => $brand->id]);

        $this->getJson("/api/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Brand retrieved successfully.')
            ->assertJsonPath('data.id', $brand->id)
            ->assertJsonPath('data.collections_count', 3);
    });

    it('returns 404 for a missing brand', function () {
        $this->getJson('/api/brands/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
    });

    // ---------- UPDATE ----------

    it('updates a brand', function () {
        $brand = Brand::factory()->create(['name' => 'Old', 'slug' => 'old']);

        $this->putJson("/api/brands/{$brand->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('message', 'Brand updated successfully.')
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'New Name']);
    });

    it('lets a brand keep its own name and slug on update', function () {
        $brand = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);

        $this->putJson("/api/brands/{$brand->id}", ['name' => 'RFL', 'slug' => 'rfl'])
            ->assertOk();
    });

    it('rejects an update to another brand name', function () {
        Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);
        $brand = Brand::factory()->create(['name' => 'OTOBI', 'slug' => 'otobi']);

        $this->putJson("/api/brands/{$brand->id}", ['name' => 'RFL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('can deactivate a brand', function () {
        $brand = Brand::factory()->create();

        $this->putJson("/api/brands/{$brand->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    // ---------- DELETE ----------

    it('deletes a brand without collections', function () {
        $brand = Brand::factory()->create();

        $this->deleteJson("/api/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Brand deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    });

    it('blocks deleting a brand that has collections', function () {
        $brand = Brand::factory()->create();
        Collection::factory()->create(['brand_id' => $brand->id]);

        $this->deleteJson("/api/brands/{$brand->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This brand has collections. Delete or move them first.');

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        expect(Collection::count())->toBe(1);
    });

    it('returns 404 when deleting a missing brand', function () {
        $this->deleteJson('/api/brands/999')->assertNotFound();
    });
});
