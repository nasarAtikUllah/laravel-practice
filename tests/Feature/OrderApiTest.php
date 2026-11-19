<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ReservationStatus;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ShippingOption;
use App\Models\User;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function orderBody(string $hash, array $overrides = []): array
{
    return array_merge([
        'cart_hash' => $hash,
        'payment_method' => 'cod',
        'shipping_name' => 'Rahim Uddin',
        'shipping_phone' => '01712345678',
        'shipping_address' => 'House 12, Road 5, Dhanmondi',
        'shipping_district' => 'Dhaka',
    ], $overrides);
}

function postOrder(object $test, string $hash, array $overrides = [], ?string $key = 'key-12345678')
{
    return $test->postJson('/api/orders', orderBody($hash, $overrides), $key === null ? [] : ['Idempotency-Key' => $key]);
}

/** A number in the right format that no order has. */
function missingOrderNumber(): string
{
    return 'ORD-000101-999999';
}

it('rejects guests on every order route', function () {
    $number = missingOrderNumber();

    $this->getJson('/api/orders')->assertUnauthorized();
    $this->postJson('/api/orders', [])->assertUnauthorized();
    $this->getJson("/api/orders/{$number}")->assertUnauthorized();
    $this->postJson("/api/orders/{$number}/cancel")->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->ship = seedShipping();
        $this->carts = app(CartService::class);
    });

    it('tells the customer to choose cash on delivery when the order is too small for online payment', function () {
        ShippingOption::query()->delete();
        ShippingOption::factory()->asDefault()->create(['fee' => 0]);
        $hash = readyCart($this->user, [[buyable(5, ['stock' => 10]), 1]]);

        postOrder($this, $hash, ['payment_method' => 'online'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Online payment is available for orders of 10.00 or more. Please choose cash on delivery.')
            ->assertJsonPath('data', null);

        expect(Order::count())->toBe(0);
    });
    // ---------- Checkout ----------

    it('places a cash on delivery order', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $hash = readyCart($this->user, [[$variant, 2]]);

        $response = postOrder($this, $hash)
            ->assertCreated()
            ->assertJsonPath('message', 'Order placed successfully.')
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.items_total', '2000.00')
            ->assertJsonPath('data.delivery_fee', '60.00')
            ->assertJsonPath('data.grand_total', '2060.00')
            ->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.shipping.option.code', 'inside-city')
            ->assertJsonPath('data.shipping.name', 'Rahim Uddin')
            ->assertJsonPath('data.shipping.phone', '01712345678')
            ->assertJsonPath('data.shipping.district', 'Dhaka')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.line_payable', '2000.00')
            ->assertJsonPath('data.history.0.to', 'pending_confirmation')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.cancelled_by');

        expect($response->json('data.number'))->toMatch('/^ORD-\d{6}-\d{6}$/');
        expect($variant->fresh()->stock)->toBe(8);
    });

    it('puts the date and the id in the order number', function () {
        $this->freezeTime();
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        $number = postOrder($this, $hash)->assertCreated()->json('data.number');
        $order = Order::firstOrFail();

        expect($number)->toBe('ORD-'.now()->format('ymd').'-'.sprintf('%06d', $order->id));
        expect($number)->toBe(Order::numberFor($order->id));
    });

    it('finds an order by the number that checkout returned', function () {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);
        $number = postOrder($this, $hash)->assertCreated()->json('data.number');

        $this->getJson("/api/orders/{$number}")->assertOk()->assertJsonPath('data.number', $number);
    });

    it('places an online order that waits for payment', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $hash = readyCart($this->user, [[$variant, 2]]);

        postOrder($this, $hash, ['payment_method' => 'online'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.can_cancel', true);

        $fresh = $variant->fresh();

        expect($fresh->stock)->toBe(10);
        expect($fresh->reserved)->toBe(2);
    });

    it('works from the cart to the order through the API', function () {
        $variant = buyable(1000, ['stock' => 10]);

        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();
        $hash = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

        postOrder($this, $hash)->assertCreated();

        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.lines', []);
    });

    it('lets staff and admins place orders as customers', function (string $role) {
        $user = User::factory()->{$role}()->create();
        Sanctum::actingAs($user);

        postOrder($this, readyCart($user, [[buyable(1000, ['stock' => 5]), 1]]))->assertCreated();
    })->with(['staff', 'admin']);

    it('saves the phone number in one format', function (string $phone) {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        postOrder($this, $hash, ['shipping_phone' => $phone])->assertCreated();

        expect(Order::firstOrFail()->shipping_phone)->toBe('01712345678');
    })->with(['+8801712345678', '8801712345678', '017-1234-5678', '017 1234 5678', '01712345678']);

    it('rejects an invalid phone number', function (string $phone) {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        postOrder($this, $hash, ['shipping_phone' => $phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_phone');

        expect(Order::count())->toBe(0);
    })->with(['12345', '01212345678', '0171234567', '017123456789', 'abcdefghijk', '+8801212345678']);

    it('saves the district with its official spelling', function (string $input, string $expected) {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        postOrder($this, $hash, ['shipping_district' => $input])->assertCreated();

        expect(Order::firstOrFail()->shipping_district)->toBe($expected);
    })->with([
        ['dhaka', 'Dhaka'],
        ['Chittagong', 'Chattogram'],
        ["cox's bazar", "Cox's Bazar"],
    ]);

    it('rejects a district that does not exist', function () {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        postOrder($this, $hash, ['shipping_district' => 'Atlantis'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_district');
    });

    it('requires the delivery details, the payment method and the cart hash', function () {
        $this->postJson('/api/orders', [], ['Idempotency-Key' => 'key-12345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'cart_hash', 'payment_method', 'shipping_name', 'shipping_phone', 'shipping_address', 'shipping_district',
            ]);
    });

    it('rejects an invalid payment method or cart hash', function () {
        postOrder($this, str_repeat('a', 64), ['payment_method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');

        postOrder($this, 'short')
            ->assertStatus(422)->assertJsonValidationErrors('cart_hash');
    });

    it('rejects text that is too short or too long', function (string $field, string $value) {
        postOrder($this, str_repeat('a', 64), [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        ['shipping_name', 'A'],
        ['shipping_name', str_repeat('a', 101)],
        ['shipping_address', 'Road 5'],
        ['shipping_address', str_repeat('a', 501)],
        ['note', str_repeat('a', 501)],
    ]);

    it('requires a valid Idempotency-Key header', function (?string $key) {
        postOrder($this, str_repeat('a', 64), [], $key)
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    })->with([null, 'short', 'has space!', 'this-key-is-far-too-long-'.'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx']);

    it('gives the same order back when the same key is used again', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $hash = readyCart($this->user, [[$variant, 2]]);

        $first = postOrder($this, $hash, [], 'retry-key-0001')->assertCreated();
        $second = postOrder($this, $hash, [], 'retry-key-0001')
            ->assertOk()
            ->assertJsonPath('message', 'Order already placed.');

        expect($second->json('data.number'))->toBe($first->json('data.number'));
        expect(Order::count())->toBe(1);
        expect($variant->fresh()->stock)->toBe(8);
        expect(InventoryMovement::count())->toBe(1);
    });

    it('ignores fields the client must not set', function () {
        $hash = readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]);

        postOrder($this, $hash, [
            'grand_total' => 1,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'user_id' => 999,
            'delivery_fee' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('data.grand_total', '1060.00')
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.payment_status', 'unpaid');

        expect(Order::firstOrFail()->user_id)->toBe($this->user->id);
    });

    it('refuses a cart hash that does not match the cart', function () {
        $variant = buyable(1000, ['stock' => 10]);
        readyCart($this->user, [[$variant, 1]]);

        postOrder($this, str_repeat('a', 64))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Your cart changed since you prepared it. Please review your cart and try again.')
            ->assertJsonPath('data', null);

        expect(Order::count())->toBe(0);
        expect($variant->fresh()->stock)->toBe(10);
    });

    it('refuses a cart that is not ready and lists the problems', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $hash = readyCart($this->user, [[$variant, 1]]);
        $variant->product->update(['status' => ProductStatus::Draft]);

        postOrder($this, $hash)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your cart needs attention before checkout.')
            ->assertJsonPath('errors.0.type', 'unavailable');
    });

    it('refuses an empty cart', function () {
        $hash = $this->carts->prepare($this->user)['cart_hash'];

        postOrder($this, $hash)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'cart_empty');
    });

    it('refuses an order when the coupon is no longer valid', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);
        $variant = buyable(1000, ['stock' => 10]);
        $hash = readyCart($this->user, [[$variant, 1]], 'SAVE10');
        $coupon->update(['is_active' => false]);

        postOrder($this, $hash)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'coupon_invalid');

        expect(Order::count())->toBe(0);
        expect(CouponRedemption::count())->toBe(0);
    });

    it('refuses an order when stock ran out after the cart was prepared', function () {
        $variant = buyable(1000, ['stock' => 5]);
        $hash = readyCart($this->user, [[$variant, 4]]);
        $variant->update(['stock' => 3]);

        postOrder($this, $hash)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'not_enough_stock');

        expect(Order::count())->toBe(0);
        expect($variant->fresh()->stock)->toBe(3);
    });

    it('limits checkout to 10 requests per minute', function () {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/orders', [])->assertStatus(422);
        }

        $this->postJson('/api/orders', [])->assertStatus(429);
    });

    // ---------- List ----------

    it('lists only my orders, newest first, without the items', function () {
        $first = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));
        $second = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 2]]));
        Order::factory()->create(); // another customer's order

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('message', 'Orders retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.number', $second->number)
            ->assertJsonPath('data.1.number', $first->number)
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.history');
    });

    it('shows an empty list when I have no orders', function () {
        Order::factory()->create();

        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    });

    it('paginates 10 orders per page and supports per_page', function () {
        Order::factory()->count(12)->for($this->user)->create();

        $this->getJson('/api/orders')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/orders?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 5);
    });

    it('filters my orders by status', function () {
        Order::factory()->for($this->user)->create(['status' => OrderStatus::Delivered]);
        Order::factory()->for($this->user)->create(['status' => OrderStatus::Cancelled]);

        $this->getJson('/api/orders?status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
    });

    it('rejects bad list parameters', function (string $query, string $field) {
        $this->getJson("/api/orders?{$query}")->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        ['status=banana', 'status'],
        ['page=abc', 'page'],
        ['page=0', 'page'],
        ['per_page=51', 'per_page'],
    ]);

    it('uses the same number of queries however many orders I have', function () {
        Order::factory()->count(2)->for($this->user)->create();

        DB::enableQueryLog();
        $this->getJson('/api/orders')->assertOk();
        $small = count(DB::getQueryLog());

        Order::factory()->count(8)->for($this->user)->create();

        DB::flushQueryLog();
        $this->getJson('/api/orders')->assertOk();
        $large = count(DB::getQueryLog());

        expect($large)->toBe($small);
    });

    // ---------- Show ----------

    it('shows my order with its items, coupon copy and history', function () {
        makeCoupon(['code' => 'SAVE10']);
        $variant = buyable(1000, ['title' => '24 cm', 'sku' => 'PAN-24', 'stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]], 'SAVE10'));

        $this->getJson("/api/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('message', 'Order retrieved successfully.')
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.items_total', '1000.00')
            ->assertJsonPath('data.discount_total', '100.00')
            ->assertJsonPath('data.delivery_fee', '60.00')
            ->assertJsonPath('data.grand_total', '960.00')
            ->assertJsonPath('data.coupon.code', 'SAVE10')
            ->assertJsonPath('data.coupon.type', 'percentage')
            ->assertJsonPath('data.coupon.value', '10.00')
            ->assertJsonPath('data.items.0.variant_id', $variant->id)
            ->assertJsonPath('data.items.0.variant_title', '24 cm')
            ->assertJsonPath('data.items.0.sku', 'PAN-24')
            ->assertJsonPath('data.items.0.unit_price', '1000.00')
            ->assertJsonPath('data.items.0.discount', '100.00')
            ->assertJsonPath('data.items.0.line_payable', '900.00')
            ->assertJsonPath('data.history.0.from', null)
            ->assertJsonPath('data.history.0.to', 'pending_confirmation')
            ->assertJsonPath('data.history.0.note', 'Order placed')
            ->assertJsonMissingPath('data.history.0.user');
    });

    it('shows an order made by the factory in the same number format', function () {
        $order = Order::factory()->for($this->user)->create();

        expect($order->number)->toMatch('/^ORD-\d{6}-\d{6}$/');

        $this->getJson("/api/orders/{$order->number}")->assertOk()->assertJsonPath('data.number', $order->number);
    });

    it('does not show an order of another customer', function () {
        $other = Order::factory()->create();

        $this->getJson("/api/orders/{$other->number}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
    });

    it('returns 404 for an order that does not exist', function () {
        $this->getJson('/api/orders/'.missingOrderNumber())->assertNotFound();
        $this->postJson('/api/orders/'.missingOrderNumber().'/cancel')->assertNotFound();
    });

    it('returns 404 for a number in the wrong format', function (string $number) {
        $this->getJson("/api/orders/{$number}")->assertNotFound();
        $this->postJson("/api/orders/{$number}/cancel")->assertNotFound();
    })->with(['not-a-number', 'ORD-2026-000001', 'ord-261117-000001', 'ORD-261117-1', '123']);

    it('shows that an order that was shipped cannot be cancelled', function () {
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));
        $order->update(['status' => OrderStatus::Shipped]);

        $this->getJson("/api/orders/{$order->number}")->assertJsonPath('data.can_cancel', false);
    });

    // ---------- Cancel ----------

    it('cancels a cash on delivery order and puts the stock and the coupon use back', function () {
        $coupon = makeCoupon(['code' => 'ONCE']);
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]], 'ONCE'));

        expect($variant->fresh()->stock)->toBe(8);

        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Order cancelled successfully.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Cancelled by customer')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.from', 'pending_confirmation')
            ->assertJsonPath('data.history.1.to', 'cancelled');

        $fresh = $order->fresh();

        expect($fresh->status)->toBe(OrderStatus::Cancelled);
        expect($fresh->cancelled_at)->not->toBeNull();
        expect($fresh->cancelled_by)->toBe($this->user->id);
        expect($fresh->expires_at)->toBeNull();
        expect($variant->fresh()->stock)->toBe(10);
        expect(CouponRedemption::firstOrFail()->status)->toBe(RedemptionStatus::Released);

        $types = InventoryMovement::orderBy('id')->get()->map(fn ($m) => $m->type->value)->all();

        expect($types)->toBe(['sale', 'cancellation']);
    });

    it('lets the customer use the coupon again after cancelling', function () {
        makeCoupon(['code' => 'ONCE']);
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 10]), 1]], 'ONCE'));

        $this->postJson("/api/orders/{$order->number}/cancel")->assertOk();

        $this->carts->addItem($this->user, buyable(1000, ['stock' => 10])->id, 1);

        expect($this->carts->applyCoupon($this->user, 'ONCE')['coupon']['applied'])->toBeTrue();
    });

    it('saves the reason the customer gives', function () {
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));

        $this->postJson("/api/orders/{$order->number}/cancel", ['reason' => 'Ordered by mistake'])
            ->assertOk()
            ->assertJsonPath('data.cancel_reason', 'Ordered by mistake')
            ->assertJsonPath('data.history.1.note', 'Ordered by mistake');
    });

    it('rejects a reason that is too long', function () {
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));

        $this->postJson("/api/orders/{$order->number}/cancel", ['reason' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        expect($order->fresh()->status)->toBe(OrderStatus::PendingConfirmation);
    });

    it('puts back every line of an order', function () {
        $a = buyable(1000, ['stock' => 10]);
        $b = buyable(500, ['stock' => 20]);
        $order = placeOrder($this->user, readyCart($this->user, [[$a, 2], [$b, 3]]));

        $this->postJson("/api/orders/{$order->number}/cancel")->assertOk();

        expect($a->fresh()->stock)->toBe(10);
        expect($b->fresh()->stock)->toBe(20);
    });

    it('cancels an online order that is waiting for payment and releases the hold', function () {
        makeCoupon(['code' => 'SAVE10']);
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]], 'SAVE10'), ['payment_method' => 'online']);

        expect($variant->fresh()->reserved)->toBe(2);

        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fresh = $variant->fresh();

        expect($fresh->stock)->toBe(10);
        expect($fresh->reserved)->toBe(0);
        expect(InventoryReservation::firstOrFail()->status)->toBe(ReservationStatus::Released);
        expect(CouponRedemption::firstOrFail()->status)->toBe(RedemptionStatus::Released);
        expect(InventoryMovement::count())->toBe(0);
    });

    it('cancels an online order whose hold already expired without releasing twice', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]), ['payment_method' => 'online']);

        $this->travel(16)->minutes();
        app(InventoryService::class)->releaseExpired();
        app(CouponService::class)->releaseExpired();

        expect($variant->fresh()->reserved)->toBe(0);

        $this->postJson("/api/orders/{$order->number}/cancel")->assertOk();

        expect($variant->fresh()->reserved)->toBe(0);
        expect($variant->fresh()->stock)->toBe(10);
    });

    it('cancels a confirmed order that was not paid', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));
        $order->update(['status' => OrderStatus::Confirmed]);

        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertOk()
            ->assertJsonPath('data.history.1.from', 'confirmed');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('cannot cancel an order that was shipped, delivered or cancelled already', function (OrderStatus $status) {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));
        $order->update(['status' => $status]);

        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order can no longer be cancelled.')
            ->assertJsonPath('data', null);

        expect($order->fresh()->status)->toBe($status);
        expect($variant->fresh()->stock)->toBe(8);
        expect(InventoryMovement::count())->toBe(1);
    })->with([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled]);

    it('cancels only once when the request is repeated', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));

        $this->postJson("/api/orders/{$order->number}/cancel")->assertOk();
        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order can no longer be cancelled.');

        expect($variant->fresh()->stock)->toBe(10);
        expect(InventoryMovement::where('type', 'cancellation')->count())->toBe(1);
        expect($order->statusHistory()->count())->toBe(2);
    });

    it('cannot cancel an order that was paid', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));
        $order->update(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);

        $this->postJson("/api/orders/{$order->number}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order has already been paid. Please contact us to cancel it and get a refund.');

        $this->getJson("/api/orders/{$order->number}")->assertJsonPath('data.can_cancel', false);

        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
        expect($variant->fresh()->stock)->toBe(8);
    });

    it('cancels an order that needed no payment', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]]));
        $order->update(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::NotRequired]);

        $this->postJson("/api/orders/{$order->number}/cancel")->assertOk();

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('does not let a customer cancel the order of another customer', function () {
        $other = User::factory()->create();
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($other, readyCart($other, [[$variant, 2]]));

        $this->postJson("/api/orders/{$order->number}/cancel")->assertNotFound();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingConfirmation);
        expect($variant->fresh()->stock)->toBe(8);
    });
});
