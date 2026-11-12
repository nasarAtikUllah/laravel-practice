<?php

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on every collection route', function () {
    $collection = Collection::factory()->create();

    $this->getJson("/api/brands/{$collection->brand_id}/collections")->assertUnauthorized();
    $this->postJson("/api/brands/{$collection->brand_id}/collections", ['name' => 'X'])->assertUnauthorized();
    $this->getJson("/api/collections/{$collection->id}")->assertUnauthorized();
    $this->putJson("/api/collections/{$collection->id}", ['name' => 'X'])->assertUnauthorized();
    $this->deleteJson("/api/collections/{$collection->id}")->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
        $this->brand = Brand::factory()->create();
    });

    // ---------- LIST ----------

    it('lists only the collections of the given brand', function () {
        Collection::factory()->count(3)->create(['brand_id' => $this->brand->id]);
        Collection::factory()->count(2)->create(); // belongs to other brands

        $this->getJson("/api/brands/{$this->brand->id}/collections")
            ->assertOk()
            ->assertJsonPath('message', 'Collections retrieved successfully.')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    });

    it('filters collections by is_active', function () {
        Collection::factory()->create(['brand_id' => $this->brand->id, 'is_active' => true]);
        Collection::factory()->create(['brand_id' => $this->brand->id, 'is_active' => false]);

        $this->getJson("/api/brands/{$this->brand->id}/collections?is_active=0")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    });

    it('returns 404 when listing collections of a missing brand', function () {
        $this->getJson('/api/brands/999/collections')->assertNotFound();
    });

    // ---------- CREATE ----------

    it('creates a collection under a brand', function () {
        $this->postJson("/api/brands/{$this->brand->id}/collections", ['name' => 'Living Room'])
            ->assertCreated()
            ->assertJsonPath('message', 'Collection created successfully.')
            ->assertJsonPath('data.slug', 'living-room')
            ->assertJsonPath('data.brand_id', $this->brand->id);

        $this->assertDatabaseHas('collections', [
            'brand_id' => $this->brand->id,
            'slug' => 'living-room',
        ]);
    });

    it('requires a name', function () {
        $this->postJson("/api/brands/{$this->brand->id}/collections", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('rejects a duplicate name inside the same brand', function () {
        Collection::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Kitchen',
            'slug' => 'kitchen',
        ]);

        $this->postJson("/api/brands/{$this->brand->id}/collections", ['name' => 'Kitchen'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        expect(Collection::count())->toBe(1);
    });

    it('allows the same name in a different brand', function () {
        $otherBrand = Brand::factory()->create();
        Collection::factory()->create([
            'brand_id' => $otherBrand->id,
            'name' => 'Kitchen',
            'slug' => 'kitchen',
        ]);

        $this->postJson("/api/brands/{$this->brand->id}/collections", ['name' => 'Kitchen'])
            ->assertCreated();
    });

    it('rejects a name that is too long', function () {
        $this->postJson("/api/brands/{$this->brand->id}/collections", ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('returns 404 when creating under a missing brand', function () {
        $this->postJson('/api/brands/999/collections', ['name' => 'Kitchen'])
            ->assertNotFound();
    });

    // ---------- SHOW ----------

    it('shows a collection with its brand', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);

        $this->getJson("/api/collections/{$collection->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Collection retrieved successfully.')
            ->assertJsonPath('data.id', $collection->id)
            ->assertJsonPath('data.brand.id', $this->brand->id);
    });

    it('returns 404 for a missing collection', function () {
        $this->getJson('/api/collections/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
    });

    // ---------- UPDATE ----------

    it('updates a collection', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);

        $this->putJson("/api/collections/{$collection->id}", ['name' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('message', 'Collection updated successfully.')
            ->assertJsonPath('data.name', 'Updated');
    });

    it('lets a collection keep its own name on update', function () {
        $collection = Collection::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Kitchen',
            'slug' => 'kitchen',
        ]);

        $this->putJson("/api/collections/{$collection->id}", ['name' => 'Kitchen', 'slug' => 'kitchen'])
            ->assertOk();
    });

    it('rejects an update to a name used by another collection of the same brand', function () {
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Kitchen', 'slug' => 'kitchen']);
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Bedroom', 'slug' => 'bedroom']);

        $this->putJson("/api/collections/{$collection->id}", ['name' => 'Kitchen'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('does not move a collection to another brand', function () {
        $otherBrand = Brand::factory()->create();
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);

        $this->putJson("/api/collections/{$collection->id}", ['brand_id' => $otherBrand->id])
            ->assertOk();

        expect($collection->fresh()->brand_id)->toBe($this->brand->id);
    });

    // ---------- DELETE ----------
    it('blocks deleting a collection that has products', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);
        Product::factory()->for($collection)->create();

        $this->deleteJson("/api/collections/{$collection->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This collection has products. Move or delete them first.');

        $this->assertDatabaseHas('collections', ['id' => $collection->id]);
    });

    it('blocks deleting a collection whose products are soft-deleted', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);
        Product::factory()->for($collection)->create()->delete();

        $this->deleteJson("/api/collections/{$collection->id}")->assertStatus(409);
    });

    it('deletes a collection', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id]);

        $this->deleteJson("/api/collections/{$collection->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Collection deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
    });

    it('returns 404 when deleting a missing collection', function () {
        $this->deleteJson('/api/collections/999')->assertNotFound();
    });

    it('lists the collections of all brands', function () {
        Collection::factory()->count(2)->create(['brand_id' => $this->brand->id]);
        Collection::factory()->count(3)->create();

        $this->getJson('/api/collections')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'products_count', 'brand' => ['id', 'name', 'slug']]]]);
    });

    it('filters the collection list by brand_id', function () {
        Collection::factory()->count(2)->create(['brand_id' => $this->brand->id]);
        Collection::factory()->count(3)->create();

        $this->getJson("/api/collections?brand_id={$this->brand->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('rejects a brand_id that does not exist in the collection list', function () {
        $this->getJson('/api/collections?brand_id=999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    });

    it('filters the collection list by is_active', function () {
        Collection::factory()->create(['brand_id' => $this->brand->id]);
        Collection::factory()->create(['brand_id' => $this->brand->id, 'is_active' => false]);

        $this->getJson('/api/collections?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    });

    it('supports per_page and rejects bad paging values', function () {
        Collection::factory()->count(8)->create(['brand_id' => $this->brand->id]);

        $this->getJson('/api/collections?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/collections?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/collections?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
        $this->getJson('/api/collections?page=abc')->assertStatus(422)->assertJsonValidationErrors('page');
    });

    it('searches the collection list by name', function () {
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Kitchen']);
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Bedroom']);

        $this->getJson('/api/collections?search=kit')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kitchen');
    });

    it('matches a percent sign in the search literally', function () {
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => '50% Off']);
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Other']);

        $this->getJson('/api/collections?search=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '50% Off');
    })->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no default LIKE escape character.');

    it('lets the brand in the url win over the brand_id filter', function () {
        $other = Brand::factory()->create();
        Collection::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Mine']);
        Collection::factory()->create(['brand_id' => $other->id, 'name' => 'Not mine']);

        $this->getJson("/api/brands/{$this->brand->id}/collections?brand_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    });
});
