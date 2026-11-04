<?php

// productvarient test
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function existingVariant(Product $product, array $overrides = []): ProductVariant
{
    return ProductVariant::factory()->create(array_merge([
        'product_id' => $product->id,
        'sku' => 'PAN-24',
        'price' => 1200,
        'compare_price' => 1500,
        'stock' => 10,
    ], $overrides));
}

it('rejects guests on every variant route', function () {
    $product = makeProduct();
    $variant = $product->variants()->first();

    $this->getJson("/api/products/{$product->id}/variants")->assertUnauthorized();
    $this->postJson("/api/products/{$product->id}/variants", [])->assertUnauthorized();
    $this->getJson("/api/variants/{$variant->id}")->assertUnauthorized();
    $this->putJson("/api/variants/{$variant->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/variants/{$variant->id}")->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
        $this->product = makeProduct(['status' => ProductStatus::Draft], 0);
        $this->url = "/api/products/{$this->product->id}/variants";
    });

    // ---------- LIST ----------

    it('lists only the variants of the given product', function () {
        ProductVariant::factory()->count(3)->create(['product_id' => $this->product->id]);
        makeProduct([], 2);

        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('message', 'Variants retrieved successfully.')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    });

    it('does not list soft-deleted variants', function () {
        ProductVariant::factory()->create(['product_id' => $this->product->id]);
        ProductVariant::factory()->create(['product_id' => $this->product->id])->delete();

        $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
    });

    it('returns 404 when listing variants of a missing product', function () {
        $this->getJson('/api/products/999/variants')->assertNotFound();
    });

    // ---------- CREATE ----------

    it('creates a variant', function () {
        $response = $this->postJson($this->url, variantPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Variant created successfully.')
            ->assertJsonPath('data.title', '24 cm')
            ->assertJsonPath('data.sku', 'PAN-24')
            ->assertJsonPath('data.stock', 10)
            ->assertJsonPath('data.product_id', $this->product->id);

        expect((float) $response->json('data.price'))->toBe(1200.0);
        expect((float) $response->json('data.compare_price'))->toBe(1500.0);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'PAN-24',
            'product_id' => $this->product->id,
            'stock' => 10,
        ]);
    });

    it('trims and uppercases the sku', function () {
        $this->postJson($this->url, variantPayload(['sku' => ' pan-24 ']))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'PAN-24');
    });

    it('sets stock to 0 when it is not sent', function () {
        $payload = variantPayload();
        unset($payload['stock']);

        $this->postJson($this->url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.stock', 0);
    });

    it('creates a variant without a compare price', function () {
        $this->postJson($this->url, variantPayload(['compare_price' => null]))
            ->assertCreated()
            ->assertJsonPath('data.compare_price', null);
    });

    it('accepts a price with two decimals', function () {
        $response = $this->postJson($this->url, variantPayload(['price' => 1200.5, 'compare_price' => null]))
            ->assertCreated();

        expect((float) $response->json('data.price'))->toBe(1200.5);
    });

    it('requires title, sku and price', function () {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'sku', 'price']);
    });

    it('rejects a duplicate sku', function () {
        existingVariant($this->product);

        $this->postJson($this->url, variantPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    });

    it('rejects a duplicate sku in a different letter case', function () {
        existingVariant($this->product, ['sku' => 'PAN-24']);

        $this->postJson($this->url, variantPayload(['sku' => 'pan-24']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    });

    it('lets the sku of a deleted variant be used again', function () {
        $variant = existingVariant($this->product);

        $this->deleteJson("/api/variants/{$variant->id}")->assertOk();

        $this->postJson($this->url, variantPayload())
            ->assertCreated()
            ->assertJsonPath('data.sku', 'PAN-24');
    });

    it('renames the sku of a deleted variant', function () {
        $variant = existingVariant($this->product);

        $this->deleteJson("/api/variants/{$variant->id}")->assertOk();

        expect(ProductVariant::withTrashed()->find($variant->id)->sku)->toBe("PAN-24-DELETED-{$variant->id}");
    });

    it('rejects an sku with invalid characters', function (string $sku) {
        $this->postJson($this->url, variantPayload(['sku' => $sku]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    })->with(['AB C', 'AB/C', '-ABC', 'AB$']);

    it('rejects an invalid price', function (mixed $price) {
        $this->postJson($this->url, variantPayload(['price' => $price]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    })->with([0, -5, 'abc', 10.555]);

    it('rejects a compare price lower than the price', function () {
        $this->postJson($this->url, variantPayload(['price' => 1200, 'compare_price' => 1000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('compare_price');
    });

    it('rejects a compare price equal to the price', function () {
        $this->postJson($this->url, variantPayload(['price' => 1200, 'compare_price' => 1200]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('compare_price');
    });

    it('rejects an invalid stock', function (mixed $stock) {
        $this->postJson($this->url, variantPayload(['stock' => $stock]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');
    })->with([-1, 1.5, 1000001, 'abc']);

    it('rejects a title that is too long', function () {
        $this->postJson($this->url, variantPayload(['title' => str_repeat('a', 256)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    });

    it('returns 404 when creating under a missing product', function () {
        $this->postJson('/api/products/999/variants', variantPayload())->assertNotFound();
    });

    it('returns 404 when creating under a soft-deleted product', function () {
        $this->product->delete();

        $this->postJson($this->url, variantPayload())->assertNotFound();
    });

    it('ignores a product_id sent in the body', function () {
        $other = makeProduct([], 0);

        $this->postJson($this->url, variantPayload(['product_id' => $other->id]))->assertCreated();

        expect(ProductVariant::first()->product_id)->toBe($this->product->id);
    });

    // ---------- SHOW ----------

    it('shows a variant', function () {
        $variant = existingVariant($this->product);

        $this->getJson("/api/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Variant retrieved successfully.')
            ->assertJsonPath('data.id', $variant->id)
            ->assertJsonPath('data.sku', 'PAN-24')
            ->assertJsonPath('data.images', []);
    });

    it('returns 404 for a missing variant', function () {
        $this->getJson('/api/variants/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
    });

    it('returns 404 for a soft-deleted variant', function () {
        $variant = existingVariant($this->product);
        $variant->delete();

        $this->getJson("/api/variants/{$variant->id}")->assertNotFound();
    });

    // ---------- UPDATE ----------

    it('updates a variant', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['title' => '28 cm'])
            ->assertOk()
            ->assertJsonPath('message', 'Variant updated successfully.')
            ->assertJsonPath('data.title', '28 cm');
    });

    it('lets a variant keep its own sku', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['sku' => 'PAN-24'])->assertOk();
    });

    it('trims and uppercases the sku on update', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['sku' => ' pan-99 '])
            ->assertOk()
            ->assertJsonPath('data.sku', 'PAN-99');
    });

    it('rejects an update to the sku of another variant', function () {
        existingVariant($this->product, ['sku' => 'TAKEN-1']);
        $variant = existingVariant($this->product, ['sku' => 'MINE-1']);

        $this->putJson("/api/variants/{$variant->id}", ['sku' => 'TAKEN-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    });

    it('lowers the price below the compare price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['price' => 1000])->assertOk();
    });

    it('rejects a price above the saved compare price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['price' => 1600])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    });

    it('rejects a price equal to the saved compare price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['price' => 1500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    });

    it('rejects a compare price below the saved price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['compare_price' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('compare_price');
    });

    it('rejects a compare price equal to the saved price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['compare_price' => 1200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('compare_price');
    });

    it('changes the price and the compare price together', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['price' => 2000, 'compare_price' => 2500])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'price' => 2000, 'compare_price' => 2500]);
    });

    it('removes the compare price', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['compare_price' => null])
            ->assertOk()
            ->assertJsonPath('data.compare_price', null);
    });

    it('sets a compare price on a variant that has none', function () {
        $variant = existingVariant($this->product, ['price' => 1000, 'compare_price' => null]);

        $this->putJson("/api/variants/{$variant->id}", ['compare_price' => 1500])->assertOk();
    });

    it('rejects a negative stock on update', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['stock' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('rejects a zero price on update', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    });

    it('does not move a variant to another product', function () {
        $other = makeProduct([], 0);
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['product_id' => $other->id, 'title' => 'X'])->assertOk();

        expect($variant->fresh()->product_id)->toBe($this->product->id);
    });

    it('returns 404 when updating a missing variant', function () {
        $this->putJson('/api/variants/999', ['title' => 'X'])->assertNotFound();
    });

    it('returns 404 when updating a soft-deleted variant', function () {
        $variant = existingVariant($this->product);
        $variant->delete();

        $this->putJson("/api/variants/{$variant->id}", ['title' => 'X'])->assertNotFound();
    });

    // ---------- DELETE ----------

    it('deletes a variant', function () {
        $variant = existingVariant($this->product);

        $this->deleteJson("/api/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Variant deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    });

    it('blocks deleting the last variant of a published product', function () {
        $product = makeProduct(['status' => ProductStatus::Published], 1);
        $variant = $product->variants()->first();

        $this->deleteJson("/api/variants/{$variant->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A published product needs at least one variant. Add another variant or change the product status first.');

        $this->assertNotSoftDeleted('product_variants', ['id' => $variant->id]);
    });

    it('counts only live variants when protecting a published product', function () {
        $product = makeProduct(['status' => ProductStatus::Published], 2);
        $product->variants()->first()->delete();
        $last = $product->variants()->first();

        $this->deleteJson("/api/variants/{$last->id}")->assertStatus(409);
    });

    it('allows deleting one of two variants of a published product', function () {
        $product = makeProduct(['status' => ProductStatus::Published], 2);
        $variant = $product->variants()->first();

        $this->deleteJson("/api/variants/{$variant->id}")->assertOk();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    });

    it('allows deleting the last variant of a draft product', function () {
        $variant = existingVariant($this->product);

        $this->deleteJson("/api/variants/{$variant->id}")->assertOk();
    });

    it('returns 404 when deleting a missing variant', function () {
        $this->deleteJson('/api/variants/999')->assertNotFound();
    });

    it('writes an initial stock movement when a variant is created with stock', function () {
        $this->postJson($this->url, variantPayload(['stock' => 10]))->assertCreated();

        $movement = InventoryMovement::firstOrFail();

        expect($movement->type)->toBe(StockMovementType::Initial);
        expect($movement->quantity)->toBe(10);
        expect($movement->stock_before)->toBe(0);
        expect($movement->stock_after)->toBe(10);
        expect($movement->created_by)->not->toBeNull();
    });

    it('writes no movement when a variant is created without stock', function () {
        $payload = variantPayload();
        unset($payload['stock']);

        $this->postJson($this->url, $payload)->assertCreated();
        $this->postJson($this->url, variantPayload(['sku' => 'PAN-25', 'stock' => 0]))->assertCreated();

        expect(InventoryMovement::count())->toBe(0);
    });

    it('does not change the stock through the update endpoint', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['stock' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock')
            ->assertJsonPath('errors.stock.0', 'Stock cannot be changed here. Use the stock adjust or stock count endpoints.');

        expect($variant->fresh()->stock)->toBe(10);
        expect(InventoryMovement::count())->toBe(0);
    });

    it('refuses a stock of zero on update as well', function () {
        $variant = existingVariant($this->product);

        $this->putJson("/api/variants/{$variant->id}", ['stock' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');
    });

    it('sets a low stock level of 5 when none is sent', function () {
        $this->postJson($this->url, variantPayload())
            ->assertCreated()
            ->assertJsonPath('data.low_stock_threshold', 5);
    });

    it('saves the low stock level on create and update', function () {
        $response = $this->postJson($this->url, variantPayload(['low_stock_threshold' => 12]))
            ->assertCreated()
            ->assertJsonPath('data.low_stock_threshold', 12);

        $this->putJson('/api/variants/'.$response->json('data.id'), ['low_stock_threshold' => 3])
            ->assertOk()
            ->assertJsonPath('data.low_stock_threshold', 3);
    });

    it('rejects an invalid low stock level', function (mixed $level) {
        $this->postJson($this->url, variantPayload(['low_stock_threshold' => $level]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('low_stock_threshold');
    })->with([-1, 1.5, 'abc', 1000001]);

    it('shows reserved and available stock in the variant response', function () {
        $variant = existingVariant($this->product, ['stock' => 10, 'reserved' => 4]);

        $this->getJson("/api/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 10)
            ->assertJsonPath('data.reserved', 4)
            ->assertJsonPath('data.available', 6);
    });
});
