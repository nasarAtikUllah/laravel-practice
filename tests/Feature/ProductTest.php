<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on every product route', function () {
    $product = makeProduct();

    $this->getJson('/api/products')->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
    $this->postJson('/api/products', [])->assertUnauthorized();
    $this->getJson("/api/products/{$product->id}")->assertUnauthorized();
    $this->putJson("/api/products/{$product->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/products/{$product->id}")->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
        $this->brand = Brand::factory()->create();
        $this->collection = Collection::factory()->create(['brand_id' => $this->brand->id]);
    });

    // ---------- LIST ----------

    it('lists products with pagination meta', function () {
        Product::factory()->count(20)->for($this->collection)->create();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('message', 'Products retrieved successfully.')
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('shows variants count, total stock and in_stock', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 5]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 10]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 2)
            ->assertJsonPath('data.0.total_stock', 15)
            ->assertJsonPath('data.0.in_stock', true);
    });

    it('shows zero stock for a product without variants', function () {
        makeProduct(['collection_id' => $this->collection->id], 0);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 0)
            ->assertJsonPath('data.0.total_stock', 0)
            ->assertJsonPath('data.0.in_stock', false);
    });

    it('includes brand and collection summaries', function () {
        makeProduct(['collection_id' => $this->collection->id]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.brand.slug', $this->brand->slug)
            ->assertJsonPath('data.0.collection.id', $this->collection->id);
    });

    it('searches products by name', function () {
        Product::factory()->for($this->collection)->create(['name' => 'Steel Pan', 'slug' => 'steel-pan']);
        Product::factory()->for($this->collection)->create(['name' => 'Glass Cup', 'slug' => 'glass-cup']);

        $this->getJson('/api/products?search=pan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Steel Pan');
    });

    it('filters products by status', function () {
        Product::factory()->for($this->collection)->create();
        Product::factory()->for($this->collection)->draft()->create();

        $this->getJson('/api/products?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');
    });

    it('filters products by brand_id', function () {
        Product::factory()->for($this->collection)->create();
        $other = Product::factory()->create();

        $this->getJson("/api/products?brand_id={$other->brand_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $other->id);
    });

    it('filters products by collection_id', function () {
        Product::factory()->for($this->collection)->create();
        $other = Product::factory()->create();

        $this->getJson("/api/products?collection_id={$other->collection_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $other->id);
    });

    it('lists draft and archived products too', function () {
        Product::factory()->for($this->collection)->create();
        Product::factory()->for($this->collection)->draft()->create();
        Product::factory()->for($this->collection)->archived()->create();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(3, 'data');
    });

    it('does not list soft-deleted products', function () {
        Product::factory()->for($this->collection)->create()->delete();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    });

    // ---------- CREATE ----------

    it('creates a draft product by default', function () {
        $this->postJson('/api/products', productPayload($this->collection))
            ->assertCreated()
            ->assertJsonPath('message', 'Product created successfully.')
            ->assertJsonPath('data.name', 'RFL Non-Stick Pan')
            ->assertJsonPath('data.slug', 'rfl-non-stick-pan')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.brand.id', $this->brand->id)
            ->assertJsonPath('data.collection.id', $this->collection->id);

        $this->assertDatabaseHas('products', [
            'slug' => 'rfl-non-stick-pan',
            'brand_id' => $this->brand->id,
            'collection_id' => $this->collection->id,
            'status' => 'draft',
        ]);
    });

    it('allows the upcoming status on create', function () {
        $this->postJson('/api/products', productPayload($this->collection, ['status' => 'upcoming']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'upcoming');
    });

    it('saves seo fields', function () {
        $this->postJson('/api/products', productPayload($this->collection, [
            'seo_title' => 'Best pan',
            'seo_description' => 'A great non-stick pan.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.seo_title', 'Best pan')
            ->assertJsonPath('data.seo_description', 'A great non-stick pan.');
    });

    it('cleans a custom slug', function () {
        $this->postJson('/api/products', productPayload($this->collection, ['slug' => 'My Custom Slug']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'my-custom-slug');
    });

    it('requires brand, collection and name', function () {
        $this->postJson('/api/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_id', 'collection_id', 'name']);
    });

    it('rejects a brand that does not exist', function () {
        $this->postJson('/api/products', productPayload($this->collection, ['brand_id' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    });

    it('rejects an inactive brand', function () {
        $brand = Brand::factory()->inactive()->create();
        $collection = Collection::factory()->create(['brand_id' => $brand->id]);

        $this->postJson('/api/products', productPayload($collection))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    });

    it('rejects an inactive collection', function () {
        $collection = Collection::factory()->create(['brand_id' => $this->brand->id, 'is_active' => false]);

        $this->postJson('/api/products', productPayload($collection))
            ->assertStatus(422)
            ->assertJsonValidationErrors('collection_id');
    });

    it('rejects a collection that belongs to another brand', function () {
        $other = Collection::factory()->create();

        $this->postJson('/api/products', [
            'brand_id' => $this->brand->id,
            'collection_id' => $other->id,
            'name' => 'Test',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collection_id');

        expect(Product::count())->toBe(0);
    });

    it('rejects a status that is not draft or upcoming on create', function (string $status) {
        $this->postJson('/api/products', productPayload($this->collection, ['status' => $status]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    })->with(['published', 'archived', 'banana']);

    it('rejects a duplicate slug', function () {
        makeProduct(['collection_id' => $this->collection->id, 'name' => 'RFL Non-Stick Pan', 'slug' => 'rfl-non-stick-pan'], 0);

        $this->postJson('/api/products', productPayload($this->collection))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(Product::count())->toBe(1);
    });

    // it('keeps the slug of a soft-deleted product reserved', function () {
    //     makeProduct(['collection_id' => $this->collection->id, 'slug' => 'rfl-non-stick-pan'], 0)->delete();

    //     $this->postJson('/api/products', productPayload($this->collection))
    //         ->assertStatus(422)
    //         ->assertJsonValidationErrors('slug');
    // });

    it('lets the slug of a deleted product be used again', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'slug' => 'rfl-non-stick-pan'], 0);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $this->postJson('/api/products', productPayload($this->collection))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'rfl-non-stick-pan');
    });

    it('renames the slug of a deleted product', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'slug' => 'my-slug'], 0);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        expect(Product::withTrashed()->find($product->id)->slug)->toBe("my-slug-deleted-{$product->id}");
    });

    it('can delete and create the same product twice in a row', function () {
        foreach (range(1, 2) as $i) {
            $id = $this->postJson('/api/products', productPayload($this->collection))
                ->assertCreated()
                ->json('data.id');

            $this->deleteJson("/api/products/{$id}")->assertOk();
        }
    });

    it('lets the variant skus of a deleted product be used again', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'PAN-24']);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $new = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->postJson("/api/products/{$new->id}/variants", variantPayload(['sku' => 'PAN-24']))
            ->assertCreated();
    });

    it('rejects a name that is too long', function () {
        $this->postJson('/api/products', productPayload($this->collection, ['name' => str_repeat('a', 256)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('rejects an seo description that is too long', function () {
        $this->postJson('/api/products', productPayload($this->collection, ['seo_description' => str_repeat('a', 501)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('seo_description');
    });

    it('ignores fields that are not allowed', function () {
        $this->postJson('/api/products', productPayload($this->collection, [
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
        ]))->assertCreated();

        $product = Product::first();

        expect($product->id)->not->toBe(999);
        expect($product->created_at->year)->not->toBe(2000);
    });

    // ---------- SHOW ----------

    it('shows a product with its brand, collection and variants', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 2);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Product retrieved successfully.')
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.brand.id', $this->brand->id)
            ->assertJsonPath('data.collection.id', $this->collection->id)
            ->assertJsonPath('data.variants_count', 2)
            ->assertJsonCount(2, 'data.variants');
    });

    it('returns a null featured image when none is uploaded', function () {
        $product = makeProduct(['collection_id' => $this->collection->id]);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.featured_image', null);
    });

    it('returns 404 for a missing product', function () {
        $this->getJson('/api/products/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
    });

    it('returns 404 for a soft-deleted product', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);
        $product->delete();

        $this->getJson("/api/products/{$product->id}")->assertNotFound();
    });

    // ---------- UPDATE ----------

    it('updates a product and keeps its slug', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'name' => 'Old', 'slug' => 'old'], 0);

        $this->putJson("/api/products/{$product->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('message', 'Product updated successfully.')
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'old');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'New Name']);
    });

    it('lets a product keep its own slug on update', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'slug' => 'my-slug'], 0);

        $this->putJson("/api/products/{$product->id}", ['slug' => 'my-slug'])->assertOk();
    });

    it('rejects an update to the slug of another product', function () {
        makeProduct(['collection_id' => $this->collection->id, 'slug' => 'taken'], 0);
        $product = makeProduct(['collection_id' => $this->collection->id, 'slug' => 'mine'], 0);

        $this->putJson("/api/products/{$product->id}", ['slug' => 'taken'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    });

    it('moves a product to another collection of the same brand', function () {
        $second = Collection::factory()->create(['brand_id' => $this->brand->id]);
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", [
            'brand_id' => $this->brand->id,
            'collection_id' => $second->id,
        ])->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'collection_id' => $second->id]);
    });

    it('moves a product to another collection when only collection_id is sent', function () {
        $second = Collection::factory()->create(['brand_id' => $this->brand->id]);
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", ['collection_id' => $second->id])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'brand_id' => $this->brand->id,
            'collection_id' => $second->id,
        ]);
    });

    it('moves a product to another brand with its collection', function () {
        $otherCollection = Collection::factory()->create();
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", [
            'brand_id' => $otherCollection->brand_id,
            'collection_id' => $otherCollection->id,
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'brand_id' => $otherCollection->brand_id,
            'collection_id' => $otherCollection->id,
        ]);
    });

    it('rejects a collection of another brand when only collection_id is sent', function () {
        $other = Collection::factory()->create();
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", ['collection_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collection_id');
    });

    it('requires collection_id when brand_id is sent', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", ['brand_id' => $this->brand->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collection_id');
    });

    it('publishes a product that has a variant', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'status' => ProductStatus::Draft], 1);

        $this->putJson("/api/products/{$product->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    });

    it('rejects publishing a product without variants', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'status' => ProductStatus::Draft], 0);

        $this->putJson("/api/products/{$product->id}", ['status' => 'published'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('rejects publishing when the only variant is deleted', function () {
        $product = makeProduct(['collection_id' => $this->collection->id, 'status' => ProductStatus::Draft], 1);
        $product->variants()->first()->delete();

        $this->putJson("/api/products/{$product->id}", ['status' => 'published'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('archives a product', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 1);

        $this->putJson("/api/products/{$product->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    });

    it('rejects an invalid status on update', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 1);

        $this->putJson("/api/products/{$product->id}", ['status' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('rejects an empty name on update', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->putJson("/api/products/{$product->id}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('returns 404 when updating a missing product', function () {
        $this->putJson('/api/products/999', ['name' => 'X'])->assertNotFound();
    });

    // ---------- DELETE ----------

    it('deletes a product and its variants', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 2);
        $variantIds = $product->variants()->pluck('id');

        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Product deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        foreach ($variantIds as $id) {
            $this->assertSoftDeleted('product_variants', ['id' => $id]);
        }
    });

    it('does not delete the variants of other products', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 1);
        $other = makeProduct(['collection_id' => $this->collection->id], 1);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $this->assertNotSoftDeleted('product_variants', ['id' => $other->variants()->first()->id]);
    });

    it('returns 404 when deleting a missing product', function () {
        $this->deleteJson('/api/products/999')->assertNotFound();
    });

    it('returns 404 when deleting a product twice', function () {
        $product = makeProduct(['collection_id' => $this->collection->id], 0);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();
        $this->deleteJson("/api/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/products/{$product->id}")->assertNotFound();
    });
});
