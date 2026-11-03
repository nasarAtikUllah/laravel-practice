<?php

use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function variantWithStock(int $stock = 10, int $reserved = 0, array $overrides = []): ProductVariant
{
    return ProductVariant::factory()->create(array_merge(['stock' => $stock, 'reserved' => $reserved], $overrides));
}

// ---------- Access ----------

it('rejects guests on every inventory route', function () {
    $variant = variantWithStock();

    $this->getJson('/api/inventory')->assertUnauthorized();
    $this->getJson("/api/variants/{$variant->id}/stock-movements")->assertUnauthorized();
    $this->postJson("/api/variants/{$variant->id}/stock/adjust", [])->assertUnauthorized();
    $this->postJson("/api/variants/{$variant->id}/stock/count", [])->assertUnauthorized();
});

it('rejects customers on every inventory route', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = variantWithStock();
    $body = ['type' => 'restock', 'quantity' => 5];

    $this->getJson('/api/inventory')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.')
        ->assertJsonPath('data', null);
    $this->getJson("/api/variants/{$variant->id}/stock-movements")->assertForbidden();
    $this->postJson("/api/variants/{$variant->id}/stock/adjust", $body)->assertForbidden();
    $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 5])->assertForbidden();

    expect($variant->fresh()->stock)->toBe(10);
    expect(InventoryMovement::count())->toBe(0);
});

it('lets admins and staff use the inventory routes', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson('/api/inventory')->assertOk();
})->with(['admin', 'staff']);

it('limits inventory requests to 60 per minute', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/inventory')->assertOk();
    }

    $this->getJson('/api/inventory')->assertStatus(429);
});

describe('when staff', function () {
    beforeEach(function () {
        $this->staff = User::factory()->staff()->create();
        Sanctum::actingAs($this->staff);
    });

    // ---------- Overview ----------

    it('shows stock, reserved, available and status for each variant', function () {
        variantWithStock(10, 0, ['sku' => 'IN-1']);
        variantWithStock(8, 4, ['sku' => 'LOW-1']);
        variantWithStock(3, 3, ['sku' => 'OUT-1']);

        $response = $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonPath('message', 'Inventory retrieved successfully.');

        $rows = collect($response->json('data'))->keyBy('sku');

        expect($rows['IN-1']['status'])->toBe('in_stock');
        expect($rows['LOW-1']['stock'])->toBe(8);
        expect($rows['LOW-1']['reserved'])->toBe(4);
        expect($rows['LOW-1']['available'])->toBe(4);
        expect($rows['LOW-1']['status'])->toBe('low_stock');
        expect($rows['OUT-1']['available'])->toBe(0);
        expect($rows['OUT-1']['status'])->toBe('out_of_stock');
    });

    it('filters by stock level', function (string $filter, array $expected) {
        variantWithStock(0, 0, ['sku' => 'OUT-A']);
        variantWithStock(3, 3, ['sku' => 'OUT-B']);
        variantWithStock(5, 0, ['sku' => 'LOW-A']);
        variantWithStock(9, 4, ['sku' => 'LOW-B']);
        variantWithStock(6, 0, ['sku' => 'IN-A']);
        variantWithStock(50, 10, ['sku' => 'IN-B']);

        $skus = collect($this->getJson("/api/inventory?filter={$filter}")->assertOk()->json('data'))
            ->pluck('sku')->sort()->values()->all();

        expect($skus)->toBe($expected);
    })->with([
        'out' => ['out', ['OUT-A', 'OUT-B']],
        'low' => ['low', ['LOW-A', 'LOW-B']],
        'in' => ['in', ['IN-A', 'IN-B']],
    ]);

    it('uses the low stock level of each variant', function () {
        variantWithStock(10, 0, ['sku' => 'HIGH-LEVEL', 'low_stock_threshold' => 20]);
        variantWithStock(10, 0, ['sku' => 'LOW-LEVEL', 'low_stock_threshold' => 2]);

        $skus = collect($this->getJson('/api/inventory?filter=low')->json('data'))->pluck('sku')->all();

        expect($skus)->toBe(['HIGH-LEVEL']);
    });

    it('rejects an invalid filter', function () {
        $this->getJson('/api/inventory?filter=banana')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter');
    });

    it('filters by product', function () {
        $product = makeProduct([], 2);
        makeProduct([], 1);

        $this->getJson("/api/inventory?product_id={$product->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('searches by sku, title and product name', function () {
        makeProduct(['name' => 'Steel Pan', 'slug' => 'steel-pan'], 1);
        variantWithStock(5, 0, ['sku' => 'RFL-PAN-24']);
        variantWithStock(5, 0, ['title' => 'Blue Cup']);

        $this->getJson('/api/inventory?search=steel')->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory?search=pan-24')->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory?search=blue')->assertJsonCount(1, 'data');
    });

    it('paginates 15 variants per page', function () {
        ProductVariant::factory()->count(20)->create();

        $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('does not list deleted variants', function () {
        variantWithStock()->delete();

        $this->getJson('/api/inventory')->assertOk()->assertJsonCount(0, 'data');
    });

    it('includes the product name', function () {
        makeProduct(['name' => 'Steel Pan', 'slug' => 'steel-pan'], 1);

        $this->getJson('/api/inventory')->assertJsonPath('data.0.product.name', 'Steel Pan');
    });

    it('uses the same number of queries however many variants are listed', function () {
        ProductVariant::factory()->count(2)->create();

        DB::enableQueryLog();
        $this->getJson('/api/inventory')->assertOk();
        $small = count(DB::getQueryLog());

        ProductVariant::factory()->count(8)->create();

        DB::flushQueryLog();
        $this->getJson('/api/inventory')->assertOk();
        $large = count(DB::getQueryLog());

        expect($large)->toBe($small);
    });

    // ---------- Adjust ----------

    it('restocks a variant', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", [
            'type' => 'restock',
            'quantity' => 5,
            'reason' => 'Supplier delivery',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Stock adjusted successfully.')
            ->assertJsonPath('data.movement.type', 'restock')
            ->assertJsonPath('data.movement.quantity', 5)
            ->assertJsonPath('data.movement.stock_before', 10)
            ->assertJsonPath('data.movement.stock_after', 15)
            ->assertJsonPath('data.movement.reason', 'Supplier delivery')
            ->assertJsonPath('data.movement.created_by.id', $this->staff->id)
            ->assertJsonPath('data.variant.stock', 15)
            ->assertJsonPath('data.variant.available', 15);

        expect($variant->fresh()->stock)->toBe(15);
    });

    it('restocks without a reason', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'restock', 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('data.movement.reason', null);
    });

    it('removes damaged stock', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", [
            'type' => 'damage',
            'quantity' => 3,
            'reason' => 'Broken in storage',
        ])
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'damage')
            ->assertJsonPath('data.movement.quantity', -3)
            ->assertJsonPath('data.variant.stock', 7);
    });

    it('requires a reason for damaged stock', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'damage', 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('blocks damage that is bigger than the stock', function () {
        $variant = variantWithStock(5);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'damage', 'quantity' => 6, 'reason' => 'Lost'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough available stock. Available: 5, requested: 6.');

        expect($variant->fresh()->stock)->toBe(5);
        expect(InventoryMovement::count())->toBe(0);
    });

    it('blocks damage that would use stock held for pending orders', function () {
        $variant = variantWithStock(10, 8);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'damage', 'quantity' => 3, 'reason' => 'Lost'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough available stock. Available: 2, requested: 3.');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('rejects an invalid quantity', function (mixed $quantity) {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'restock', 'quantity' => $quantity])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    })->with([0, -1, 1.5, 'abc', 1000001]);

    it('rejects a type that is not restock or damage', function (string $type) {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => $type, 'quantity' => 1, 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        expect($variant->fresh()->stock)->toBe(10);
    })->with(['sale', 'return', 'cancellation', 'banana']);

    it('requires type and quantity', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'quantity']);
    });

    it('rejects a reason that is too long', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", [
            'type' => 'restock',
            'quantity' => 1,
            'reason' => str_repeat('a', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    });

    it('ignores fields that the client must not set', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", [
            'type' => 'restock',
            'quantity' => 5,
            'created_by' => 999,
            'stock_before' => 0,
            'stock_after' => 1,
        ])->assertCreated();

        $movement = InventoryMovement::firstOrFail();

        expect($movement->created_by)->toBe($this->staff->id);
        expect($movement->stock_before)->toBe(10);
        expect($movement->stock_after)->toBe(15);
    });

    it('lets an admin adjust stock too', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/adjust", ['type' => 'restock', 'quantity' => 1])
            ->assertCreated();
    });

    it('returns 404 when adjusting a missing or deleted variant', function () {
        $deleted = variantWithStock(10);
        $deleted->delete();

        $this->postJson('/api/variants/999/stock/adjust', ['type' => 'restock', 'quantity' => 1])->assertNotFound();
        $this->postJson("/api/variants/{$deleted->id}/stock/adjust", ['type' => 'restock', 'quantity' => 1])->assertNotFound();
    });

    // ---------- Count ----------

    it('raises the stock to a higher counted number', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 12, 'reason' => 'Monthly count'])
            ->assertCreated()
            ->assertJsonPath('message', 'Stock count saved successfully.')
            ->assertJsonPath('data.movement.type', 'correction')
            ->assertJsonPath('data.movement.quantity', 2)
            ->assertJsonPath('data.movement.reason', 'Monthly count')
            ->assertJsonPath('data.variant.stock', 12);
    });

    it('lowers the stock to a lower counted number', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 7])
            ->assertCreated()
            ->assertJsonPath('data.movement.quantity', -3)
            ->assertJsonPath('data.movement.reason', 'Stock count')
            ->assertJsonPath('data.variant.stock', 7);
    });

    it('can count a variant down to zero', function () {
        $variant = variantWithStock(4);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 0])
            ->assertCreated()
            ->assertJsonPath('data.variant.stock', 0);
    });

    it('changes nothing when the count matches the stock', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 10])
            ->assertOk()
            ->assertJsonPath('message', 'The stock already matches the counted quantity. No change was made.')
            ->assertJsonPath('data.movement', null)
            ->assertJsonPath('data.variant.stock', 10);

        expect(InventoryMovement::count())->toBe(0);
    });

    it('blocks a count below the stock held for pending orders', function () {
        $variant = variantWithStock(10, 4);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The counted quantity (3) is lower than the stock held for pending orders (4).');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('rejects an invalid counted quantity', function (mixed $quantity) {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/count", ['counted_quantity' => $quantity])
            ->assertStatus(422)
            ->assertJsonValidationErrors('counted_quantity');
    })->with([-1, 1.5, 'abc', 1000001]);

    it('requires the counted quantity', function () {
        $variant = variantWithStock(10);

        $this->postJson("/api/variants/{$variant->id}/stock/count", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('counted_quantity');
    });

    it('returns 404 when counting a missing or deleted variant', function () {
        $deleted = variantWithStock(10);
        $deleted->delete();

        $this->postJson('/api/variants/999/stock/count', ['counted_quantity' => 1])->assertNotFound();
        $this->postJson("/api/variants/{$deleted->id}/stock/count", ['counted_quantity' => 1])->assertNotFound();
    });

    // ---------- History ----------

    it('lists the movements of a variant, newest first', function () {
        $variant = variantWithStock(0);
        $inventory = app(InventoryService::class);
        $inventory->initial($variant, 10, $this->staff);
        $inventory->damage($variant, 2, 'Broken', $this->staff);

        $this->getJson("/api/variants/{$variant->id}/stock-movements")
            ->assertOk()
            ->assertJsonPath('message', 'Stock movements retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'damage')
            ->assertJsonPath('data.1.type', 'initial')
            ->assertJsonPath('data.0.created_by.name', $this->staff->name);
    });

    it('shows no user for a movement written by the system', function () {
        $variant = variantWithStock(0);
        app(InventoryService::class)->initial($variant, 5);

        $this->getJson("/api/variants/{$variant->id}/stock-movements")
            ->assertJsonPath('data.0.created_by', null);
    });

    it('shows only the movements of that variant', function () {
        $variant = variantWithStock(0);
        $other = variantWithStock(0);
        $inventory = app(InventoryService::class);
        $inventory->initial($variant, 5);
        $inventory->initial($other, 7);

        $this->getJson("/api/variants/{$variant->id}/stock-movements")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 5);
    });

    it('filters the history by type', function () {
        $variant = variantWithStock(0);
        $inventory = app(InventoryService::class);
        $inventory->initial($variant, 10);
        $inventory->damage($variant, 1, 'Broken');
        $inventory->restock($variant, 3);

        $this->getJson("/api/variants/{$variant->id}/stock-movements?type=damage")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'damage');
    });

    it('rejects an invalid type filter', function () {
        $variant = variantWithStock(0);

        $this->getJson("/api/variants/{$variant->id}/stock-movements?type=banana")
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

    it('paginates the history 20 per page', function () {
        $variant = variantWithStock(0);
        $inventory = app(InventoryService::class);

        foreach (range(1, 25) as $i) {
            $inventory->restock($variant, 1);
        }

        $this->getJson("/api/variants/{$variant->id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('still shows the history of a deleted variant', function () {
        $variant = variantWithStock(0);
        app(InventoryService::class)->initial($variant, 5);
        $variant->delete();

        $this->getJson("/api/variants/{$variant->id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns 404 for the history of a variant that never existed', function () {
        $this->getJson('/api/variants/999/stock-movements')->assertNotFound();
    });
});
