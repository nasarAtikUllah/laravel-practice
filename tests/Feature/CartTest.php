<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\ShippingOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function addToCart(object $test, ProductVariant $variant, int $quantity = 1)
{
    return $test->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => $quantity]);
}

it('rejects guests on every cart route', function () {
    $this->getJson('/api/cart')->assertUnauthorized();
    $this->deleteJson('/api/cart')->assertUnauthorized();
    $this->postJson('/api/cart/items', [])->assertUnauthorized();
    $this->patchJson('/api/cart/items/1', [])->assertUnauthorized();
    $this->deleteJson('/api/cart/items/1')->assertUnauthorized();
    $this->putJson('/api/cart/coupon', [])->assertUnauthorized();
    $this->deleteJson('/api/cart/coupon')->assertUnauthorized();
    $this->putJson('/api/cart/shipping', [])->assertUnauthorized();
    $this->postJson('/api/cart/prepare')->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // A free default delivery option keeps the totals in these tests simple.
        // The delivery rules have their own file (CartShippingTest).
        ShippingOption::factory()->asDefault()->create(['code' => 'standard', 'fee' => 0]);
    });

    // ---------- Show ----------

    it('shows an empty cart', function () {
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('message', 'Cart retrieved successfully.')
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.items_total', '0.00')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.grand_total', '0.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'cart_empty');

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id]);
    });

    it('keeps one cart per customer', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.lines');

        Sanctum::actingAs($this->user);
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.lines');

        expect(DB::table('carts')->count())->toBe(2);
    });

    it('shows the product details of a line', function () {
        $variant = buyable(1000, ['title' => '24 cm', 'sku' => 'PAN-24']);

        addToCart($this, $variant)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.product.id', $variant->product_id)
            ->assertJsonPath('data.lines.0.product.name', $variant->product->name)
            ->assertJsonPath('data.lines.0.title', '24 cm')
            ->assertJsonPath('data.lines.0.sku', 'PAN-24')
            ->assertJsonPath('data.lines.0.image', null)
            ->assertJsonPath('data.lines.0.in_stock', true);
    });

    it('shows every amount in BDT with two decimals', function () {
        addToCart($this, buyable(1200.5), 3)
            ->assertCreated()
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.lines.0.unit_price', '1200.50')
            ->assertJsonPath('data.lines.0.line_total', '3601.50')
            ->assertJsonPath('data.items_total', '3601.50')
            ->assertJsonPath('data.grand_total', '3601.50');
    });

    it('uses the same number of queries however many items the cart has', function () {
        foreach (range(1, 2) as $i) {
            addToCart($this, buyable(100))->assertCreated();
        }

        DB::enableQueryLog();
        $this->getJson('/api/cart')->assertOk();
        $small = count(DB::getQueryLog());

        foreach (range(1, 6) as $i) {
            addToCart($this, buyable(100))->assertCreated();
        }

        DB::flushQueryLog();
        $this->getJson('/api/cart')->assertOk();
        $large = count(DB::getQueryLog());

        expect($large)->toBe($small);
    });

    it('limits cart requests to 120 per minute', function () {
        foreach (range(1, 120) as $i) {
            $this->getJson('/api/cart')->assertOk();
        }

        $this->getJson('/api/cart')->assertStatus(429);
    });

    // ---------- Add ----------

    it('adds an item and shows the totals', function () {
        $variant = buyable(1000);

        addToCart($this, $variant, 2)
            ->assertCreated()
            ->assertJsonPath('message', 'Item added to cart.')
            ->assertJsonPath('data.lines.0.variant_id', $variant->id)
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_price', '1000.00')
            ->assertJsonPath('data.lines.0.line_total', '2000.00')
            ->assertJsonPath('data.lines.0.issue', null)
            ->assertJsonPath('data.items_total', '2000.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.grand_total', '2000.00')
            ->assertJsonPath('data.ready', true);

        $this->assertDatabaseHas('cart_items', [
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'price_when_added' => 1000,
        ]);
    });

    it('adds to the quantity when the same item is added again', function () {
        $variant = buyable(1000);

        addToCart($this, $variant, 2)->assertCreated();
        addToCart($this, $variant, 3)
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', 5)
            ->assertJsonPath('data.items_total', '5000.00');

        expect(DB::table('cart_items')->count())->toBe(1);
    });

    it('keeps the first price when the same item is added again after a price change', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->update(['price' => 1200]);

        addToCart($this, $variant)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.price_changed', true)
            ->assertJsonPath('data.lines.0.previous_price', '1000.00')
            ->assertJsonPath('data.lines.0.unit_price', '1200.00');
    });

    it('accepts a quantity equal to the available stock', function () {
        $variant = buyable(1000, ['stock' => 5, 'reserved' => 0]);

        addToCart($this, $variant, 5)->assertCreated();
    });

    it('refuses a quantity above the available stock', function () {
        $variant = buyable(1000, ['stock' => 5, 'reserved' => 0]);

        addToCart($this, $variant, 6)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough stock for this quantity.')
            ->assertJsonPath('data', null);

        expect(DB::table('cart_items')->count())->toBe(0);
    });

    it('does not count stock held for other orders as available', function () {
        $variant = buyable(1000, ['stock' => 5, 'reserved' => 4]);

        addToCart($this, $variant, 1)->assertCreated();
        addToCart($this, $variant, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough stock for this quantity.');
    });

    it('refuses items that cannot be bought', function () {
        $draft = ProductVariant::factory()->create([
            'product_id' => makeProduct(['status' => ProductStatus::Draft], 0)->id,
            'price' => 1000,
            'stock' => 10,
        ]);
        $inactiveBrand = ProductVariant::factory()->create([
            'product_id' => makeProduct([
                'collection_id' => Collection::factory()->create(['brand_id' => Brand::factory()->inactive()->create()->id])->id,
            ], 0)->id,
            'price' => 1000,
            'stock' => 10,
        ]);
        $deleted = tap(buyable(1000))->delete();

        foreach ([$draft->id, $inactiveBrand->id, $deleted->id, 999] as $id) {
            $this->postJson('/api/cart/items', ['variant_id' => $id, 'quantity' => 1])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This item is not available.');
        }

        expect(DB::table('cart_items')->count())->toBe(0);
    });

    it('limits the quantity of one item', function () {
        config(['cart.max_quantity_per_line' => 5]);
        $variant = buyable(1000, ['stock' => 50]);

        addToCart($this, $variant, 6)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can buy at most 5 of one item.');

        addToCart($this, $variant, 3)->assertCreated();
        addToCart($this, $variant, 3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can buy at most 5 of one item.');
    });

    it('limits the number of different items', function () {
        config(['cart.max_lines' => 2]);

        addToCart($this, buyable(100))->assertCreated();
        addToCart($this, buyable(100))->assertCreated();
        addToCart($this, buyable(100))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The cart can have at most 2 different items.');
    });

    it('still lets the quantity of an item already in a full cart change', function () {
        config(['cart.max_lines' => 2]);
        $first = buyable(100);

        addToCart($this, $first)->assertCreated();
        addToCart($this, buyable(100))->assertCreated();

        addToCart($this, $first, 2)->assertCreated()->assertJsonPath('data.lines.0.quantity', 3);
    });

    it('rejects an invalid add request', function (array $body, string $field) {
        $this->postJson('/api/cart/items', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'no variant' => [['quantity' => 1], 'variant_id'],
        'bad variant' => [['variant_id' => 'abc', 'quantity' => 1], 'variant_id'],
        'no quantity' => [['variant_id' => 1], 'quantity'],
        'quantity zero' => [['variant_id' => 1, 'quantity' => 0], 'quantity'],
        'quantity too big' => [['variant_id' => 1, 'quantity' => 1001], 'quantity'],
        'quantity decimal' => [['variant_id' => 1, 'quantity' => 1.5], 'quantity'],
    ]);

    // ---------- Update quantity ----------

    it('changes the quantity of an item', function () {
        $variant = buyable(1000);
        addToCart($this, $variant, 2)->assertCreated();

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('message', 'Cart item updated.')
            ->assertJsonPath('data.lines.0.quantity', 4)
            ->assertJsonPath('data.items_total', '4000.00');
    });

    it('can lower the quantity of an item', function () {
        $variant = buyable(1000);
        addToCart($this, $variant, 4)->assertCreated();

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', 1);
    });

    it('refuses a new quantity above the stock or the limit', function () {
        config(['cart.max_quantity_per_line' => 5]);
        $variant = buyable(1000, ['stock' => 3]);
        addToCart($this, $variant, 1)->assertCreated();

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough stock for this quantity.');

        $variant->update(['stock' => 50]);

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 6])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can buy at most 5 of one item.');
    });

    it('refuses to change an item that is not in the cart', function () {
        $variant = buyable(1000);

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 2])
            ->assertNotFound()
            ->assertJsonPath('message', 'This item is not in your cart.');
    });

    it('refuses to change an item that became unavailable', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->product->update(['status' => ProductStatus::Draft]);

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is not available.');
    });

    it('rejects an invalid new quantity', function (mixed $quantity) {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => $quantity])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    })->with([0, -1, 1.5, 'abc', 1001]);

    it('does not let a customer change the item of another customer', function () {
        $variant = buyable(1000);

        Sanctum::actingAs(User::factory()->create());
        addToCart($this, $variant, 2)->assertCreated();

        Sanctum::actingAs($this->user);
        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 5])->assertNotFound();
        $this->deleteJson("/api/cart/items/{$variant->id}")->assertNotFound();

        expect(DB::table('cart_items')->value('quantity'))->toBe(2);
    });

    // ---------- Remove ----------

    it('removes an item', function () {
        $keep = buyable(1000);
        $drop = buyable(500);
        addToCart($this, $keep)->assertCreated();
        addToCart($this, $drop)->assertCreated();

        $this->deleteJson("/api/cart/items/{$drop->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Item removed from cart.')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.variant_id', $keep->id)
            ->assertJsonPath('data.items_total', '1000.00');
    });

    it('refuses to remove an item that is not in the cart', function () {
        $this->deleteJson('/api/cart/items/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'This item is not in your cart.');
    });

    it('can remove an item that was deleted from the shop', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->delete();

        $this->deleteJson("/api/cart/items/{$variant->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.lines');
    });

    it('clears the cart and its coupon', function () {
        $variant = buyable(1000);
        makeCoupon(['code' => 'SAVE10']);
        addToCart($this, $variant)->assertCreated();
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $this->deleteJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('message', 'Cart cleared successfully.')
            ->assertJsonCount(0, 'data.lines')
            ->assertJsonPath('data.coupon', null);

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'coupon_code' => null]);
    });

    // ---------- Changes after an item was added ----------

    it('shows a price change', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->update(['price' => 1200]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.price_changed', true)
            ->assertJsonPath('data.lines.0.previous_price', '1000.00')
            ->assertJsonPath('data.lines.0.unit_price', '1200.00')
            ->assertJsonPath('data.lines.0.line_total', '1200.00')
            ->assertJsonPath('data.items_total', '1200.00')
            ->assertJsonPath('data.ready', true);
    });

    it('marks an item as unavailable and leaves it out of the totals', function () {
        $good = buyable(1000);
        $gone = buyable(500);
        addToCart($this, $good)->assertCreated();
        addToCart($this, $gone)->assertCreated();
        $gone->product->update(['status' => ProductStatus::Draft]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.1.issue', 'unavailable')
            ->assertJsonPath('data.lines.1.in_stock', false)
            ->assertJsonPath('data.items_total', '1000.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'unavailable')
            ->assertJsonPath('data.issues.0.variant_id', $gone->id);
    });

    it('still shows an item that was deleted from the shop, marked as unavailable', function () {
        $variant = buyable(1000, ['title' => '24 cm']);
        addToCart($this, $variant)->assertCreated();
        $variant->delete();

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.title', '24 cm')
            ->assertJsonPath('data.lines.0.issue', 'unavailable')
            ->assertJsonPath('data.items_total', '0.00')
            ->assertJsonPath('data.ready', false);
    });

    it('marks an item when its stock dropped below the quantity', function () {
        $variant = buyable(1000, ['stock' => 10]);
        addToCart($this, $variant, 5)->assertCreated();
        $variant->update(['stock' => 3]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.issue', 'not_enough_stock')
            ->assertJsonPath('data.items_total', '0.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'not_enough_stock');
    });

    // ---------- Coupon preview ----------

    it('applies a coupon and shows the discount', function () {
        $variant = buyable(1000);
        makeCoupon(['code' => 'SAVE10']);
        addToCart($this, $variant)->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => ' save10 '])
            ->assertOk()
            ->assertJsonPath('message', 'Coupon applied to your cart.')
            ->assertJsonPath('data.coupon.code', 'SAVE10')
            ->assertJsonPath('data.coupon.applied', true)
            ->assertJsonPath('data.coupon.discount', '100.00')
            ->assertJsonPath('data.discount', '100.00')
            ->assertJsonPath('data.total_after_discount', '900.00')
            ->assertJsonPath('data.grand_total', '900.00')
            ->assertJsonPath('data.lines.0.discount', '100.00')
            ->assertJsonPath('data.lines.0.line_payable', '900.00')
            ->assertJsonPath('data.ready', true);

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'coupon_code' => 'SAVE10']);
    });

    it('refuses a coupon code that is not valid and keeps the cart as it was', function () {
        addToCart($this, buyable(1000))->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => 'NOPE1234'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This coupon code is not valid.');

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'coupon_code' => null]);
    });

    it('refuses a coupon on an empty cart', function () {
        makeCoupon(['code' => 'SAVE10']);

        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your cart is empty.');
    });

    it('names the minimum when the cart is too small for the coupon', function () {
        makeCoupon(['code' => 'BIGONLY', 'min_order_amount' => 5000]);
        addToCart($this, buyable(1000))->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => 'BIGONLY'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The order total must be at least 5000.00 to use this coupon.');
    });

    it('requires a code', function () {
        $this->putJson('/api/cart/coupon', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('refuses a coupon this customer already used up', function () {
        $coupon = makeCoupon(['code' => 'ONCE']);
        makeRedemption($coupon, $this->user);
        addToCart($this, buyable(1000))->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => 'ONCE'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This coupon code is not valid.');
    });

    it('applies a brand coupon to the lines of that brand only', function () {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();
        $itemA = buyableForBrand($a, 1000);
        $itemB = buyableForBrand($b, 1000);
        Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

        addToCart($this, $itemA)->assertCreated();
        addToCart($this, $itemB)->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => 'BRANDA'])
            ->assertOk()
            ->assertJsonPath('data.coupon.scope', 'brands')
            ->assertJsonPath('data.discount', '100.00')
            ->assertJsonPath('data.lines.0.coupon_eligible', true)
            ->assertJsonPath('data.lines.0.discount', '100.00')
            ->assertJsonPath('data.lines.1.coupon_eligible', false)
            ->assertJsonPath('data.lines.1.discount', '0.00')
            ->assertJsonPath('data.grand_total', '1900.00');
    });

    it('leaves unavailable items out of the coupon preview', function () {
        $good = buyable(1000);
        $gone = buyable(1000);
        makeCoupon(['code' => 'SAVE10']);
        addToCart($this, $good)->assertCreated();
        addToCart($this, $gone)->assertCreated();
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $gone->product->update(['status' => ProductStatus::Draft]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.items_total', '1000.00')
            ->assertJsonPath('data.discount', '100.00');
    });

    it('shows that a coupon is no longer valid and blocks checkout', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);
        addToCart($this, buyable(1000))->assertCreated();
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $coupon->update(['is_active' => false]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.coupon.applied', false)
            ->assertJsonPath('data.coupon.message', 'This coupon code is not valid.')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.grand_total', '1000.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'coupon_invalid');
    });

    it('removes the coupon', function () {
        makeCoupon(['code' => 'SAVE10']);
        addToCart($this, buyable(1000))->assertCreated();
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $this->deleteJson('/api/cart/coupon')
            ->assertOk()
            ->assertJsonPath('message', 'Coupon removed from your cart.')
            ->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.ready', true);

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'coupon_code' => null]);
    });

    it('does not use up the coupon while it is only previewed', function () {
        $coupon = makeCoupon(['code' => 'ONCE']);
        addToCart($this, buyable(1000))->assertCreated();

        $this->putJson('/api/cart/coupon', ['code' => 'ONCE'])->assertOk();
        $this->getJson('/api/cart')->assertOk();

        expect($coupon->redemptions()->count())->toBe(0);
    });

    // ---------- Checkout preparation ----------

    it('prepares a cart that is ready', function () {
        addToCart($this, buyable(1000), 2)->assertCreated();

        $response = $this->postJson('/api/cart/prepare')
            ->assertOk()
            ->assertJsonPath('message', 'Cart is ready for checkout.')
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.expected_total', '2000.00')
            ->assertJsonPath('data.price_changes', []);

        expect($response->json('data.cart_hash'))->toBeString()->toHaveLength(64);
    });

    it('refuses to prepare an empty cart', function () {
        $this->postJson('/api/cart/prepare')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your cart needs attention before checkout.')
            ->assertJsonPath('errors.0.type', 'cart_empty');
    });

    it('refuses to prepare a cart with an unavailable item', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->product->update(['status' => ProductStatus::Draft]);

        $this->postJson('/api/cart/prepare')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'unavailable');
    });

    it('refuses to prepare a cart with an invalid coupon', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);
        addToCart($this, buyable(1000))->assertCreated();
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();
        $coupon->update(['is_active' => false]);

        $this->postJson('/api/cart/prepare')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'coupon_invalid');
    });

    it('does not change the price of a cart that is not ready', function () {
        $good = buyable(1000);
        $gone = buyable(500);
        addToCart($this, $good)->assertCreated();
        addToCart($this, $gone)->assertCreated();
        $good->update(['price' => 1200]);
        $gone->product->update(['status' => ProductStatus::Draft]);

        $this->postJson('/api/cart/prepare')->assertStatus(422);

        $this->getJson('/api/cart')->assertJsonPath('data.lines.0.price_changed', true);
    });

    it('shows price changes once and then accepts them', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $variant->update(['price' => 1200]);

        $this->postJson('/api/cart/prepare')
            ->assertOk()
            ->assertJsonPath('data.price_changes.0.variant_id', $variant->id)
            ->assertJsonPath('data.price_changes.0.previous_price', '1000.00')
            ->assertJsonPath('data.price_changes.0.unit_price', '1200.00')
            ->assertJsonPath('data.expected_total', '1200.00')
            ->assertJsonPath('data.lines.0.price_changed', false);

        $this->getJson('/api/cart')->assertJsonPath('data.lines.0.price_changed', false);
        $this->postJson('/api/cart/prepare')->assertOk()->assertJsonPath('data.price_changes', []);
    });

    it('gives the same hash when nothing changed and another hash when something changed', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();

        $first = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');
        $second = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

        expect($second)->toBe($first);

        $this->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();
        $third = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

        expect($third)->not->toBe($first);

        makeCoupon(['code' => 'SAVE10']);
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();
        $fourth = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

        expect($fourth)->not->toBe($third);
    });

    it('gives another hash when the price changes', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $before = $this->postJson('/api/cart/prepare')->json('data.cart_hash');

        $variant->update(['price' => 1100]);
        $after = $this->postJson('/api/cart/prepare')->json('data.cart_hash');

        expect($after)->not->toBe($before);
    });

    it('gives different hashes to different customers with the same cart', function () {
        $variant = buyable(1000);
        addToCart($this, $variant)->assertCreated();
        $mine = $this->postJson('/api/cart/prepare')->json('data.cart_hash');

        Sanctum::actingAs(User::factory()->create());
        addToCart($this, $variant)->assertCreated();
        $theirs = $this->postJson('/api/cart/prepare')->json('data.cart_hash');

        expect($theirs)->not->toBe($mine);
    });
});
