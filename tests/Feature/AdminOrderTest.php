<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\OrderException;
use App\Jobs\ProcessRefund;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A real cash on delivery order, with its stock sold. @return array{0: Order, 1: ProductVariant} */
function codOrderFor(User $customer, int $quantity = 2, ?string $coupon = null): array
{
    $variant = buyable(1000, ['stock' => 10]);
    $order = placeOrder($customer, readyCart($customer, [[$variant, $quantity]], $coupon));

    return [$order, $variant];
}

// ---------- Access ----------

it('rejects guests on every staff order route', function () {
    $number = Order::factory()->create()->number;

    $this->getJson('/api/admin/orders')->assertUnauthorized();
    $this->getJson("/api/admin/orders/{$number}")->assertUnauthorized();

    foreach (['confirm', 'ship', 'deliver', 'cancel'] as $action) {
        $this->postJson("/api/admin/orders/{$number}/{$action}")->assertUnauthorized();
    }
});

it('gives customers 403 on every staff order route, also for numbers that do not exist', function () {
    Sanctum::actingAs(User::factory()->create());
    $order = Order::factory()->create();

    $this->getJson('/api/admin/orders')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.');

    foreach ([$order->number, 'ORD-000101-999999'] as $number) {
        $this->getJson("/api/admin/orders/{$number}")->assertForbidden();

        foreach (['confirm', 'ship', 'deliver', 'cancel'] as $action) {
            $this->postJson("/api/admin/orders/{$number}/{$action}", ['reason' => 'Because'])->assertForbidden();
        }
    }

    expect($order->fresh()->status)->toBe(OrderStatus::PendingConfirmation);
});

it('lets staff and admins reach the staff order routes', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());
    $order = Order::factory()->create();

    $this->getJson('/api/admin/orders')->assertOk();
    $this->getJson("/api/admin/orders/{$order->number}")->assertOk();
})->with(['staff', 'admin']);

it('returns 404 for a number in the wrong format, also for staff', function (string $number) {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson("/api/admin/orders/{$number}")->assertNotFound();
    $this->postJson("/api/admin/orders/{$number}/confirm")->assertNotFound();
})->with(['not-a-number', 'ORD-2026-000001', '123']);

it('limits staff order requests to 120 per minute', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    foreach (range(1, 120) as $i) {
        $this->getJson('/api/admin/orders')->assertOk();
    }

    $this->getJson('/api/admin/orders')->assertStatus(429);
});

describe('when staff', function () {
    beforeEach(function () {
        $this->staff = User::factory()->staff()->create();
        $this->customer = User::factory()->create(['name' => 'Rahim Uddin', 'email' => 'rahim@example.com']);
        Sanctum::actingAs($this->staff);
        $this->ship = seedShipping();
    });

    // ---------- List ----------

    it('lists the orders of all customers, newest first, with the customer and the item count', function () {
        [$first] = codOrderFor($this->customer, 1);
        $other = User::factory()->create(['name' => 'Karim Hossain']);
        [$second] = codOrderFor($other, 2);

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('message', 'Orders retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.number', $second->number)
            ->assertJsonPath('data.0.customer.name', 'Karim Hossain')
            ->assertJsonPath('data.1.number', $first->number)
            ->assertJsonPath('data.1.customer.email', 'rahim@example.com')
            ->assertJsonPath('data.1.items_count', 1)
            ->assertJsonPath('data.1.available_actions', ['confirm', 'cancel'])
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.history');
    });

    it('shows an empty list when there are no orders', function () {
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    });

    it('paginates 15 orders per page and supports per_page', function () {
        Order::factory()->count(20)->create();

        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 20);
        $this->getJson('/api/admin/orders?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 5);
    });

    it('filters by status, payment method and payment status', function () {
        Order::factory()->create(['status' => OrderStatus::Delivered, 'payment_method' => PaymentMethod::Cod, 'payment_status' => PaymentStatus::Paid]);
        Order::factory()->create(['status' => OrderStatus::PendingPayment, 'payment_method' => PaymentMethod::Online, 'payment_status' => PaymentStatus::Unpaid]);
        Order::factory()->create(['status' => OrderStatus::Cancelled, 'payment_method' => PaymentMethod::Online, 'payment_status' => PaymentStatus::Unpaid]);

        $this->getJson('/api/admin/orders?status=delivered')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'delivered');
        $this->getJson('/api/admin/orders?payment_method=online')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/orders?payment_status=paid')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?payment_method=online&status=cancelled')->assertJsonCount(1, 'data');
    });

    it('searches by the start of the order number', function () {
        Order::factory()->create(['number' => 'ORD-261117-000777']);
        Order::factory()->create(['number' => 'ORD-261118-000888']);

        $this->getJson('/api/admin/orders?search=ORD-261117')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'ORD-261117-000777');
        $this->getJson('/api/admin/orders?search=ORD-261117-000777')->assertJsonCount(1, 'data');
    });

    it('searches by the start of the phone number', function () {
        Order::factory()->create(['shipping_phone' => '01811111111']);
        Order::factory()->create(['shipping_phone' => '01922222222']);

        $this->getJson('/api/admin/orders?search=0181')->assertJsonCount(1, 'data')->assertJsonPath('data.0.shipping.phone', '01811111111');
    });

    it('searches by the name on the order and by the customer name or email', function () {
        Order::factory()->create(['shipping_name' => 'Fatema Begum']);
        Order::factory()->for(User::factory()->create(['name' => 'Karim Hossain', 'email' => 'karim@example.com']))->create();
        Order::factory()->create();

        $this->getJson('/api/admin/orders?search=fatema')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?search=karim hossain')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?search=karim@example')->assertJsonCount(1, 'data');
    });

    it('filters by the date the order was placed, including the whole last day', function () {
        Order::factory()->create(['placed_at' => '2026-11-09 23:59:59']);
        Order::factory()->create(['placed_at' => '2026-11-10 00:00:00']);
        Order::factory()->create(['placed_at' => '2026-11-12 23:59:59']);
        Order::factory()->create(['placed_at' => '2026-11-13 00:00:00']);

        $this->getJson('/api/admin/orders?from=2026-11-10&to=2026-11-12')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/orders?from=2026-11-12')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/orders?to=2026-11-09')->assertJsonCount(1, 'data');
    });

    it('rejects bad list parameters', function (string $query, string $field) {
        $this->getJson("/api/admin/orders?{$query}")->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        ['status=banana', 'status'],
        ['payment_method=card', 'payment_method'],
        ['payment_status=banana', 'payment_status'],
        ['from=not-a-date', 'from'],
        ['from=2026-11-12&to=2026-11-10', 'to'],
        ['page=abc', 'page'],
        ['page=0', 'page'],
        ['per_page=101', 'per_page'],
    ]);

    it('uses the same number of queries however many orders there are', function () {
        Order::factory()->count(2)->create();

        DB::enableQueryLog();
        $this->getJson('/api/admin/orders')->assertOk();
        $small = count(DB::getQueryLog());

        Order::factory()->count(8)->create();

        DB::flushQueryLog();
        $this->getJson('/api/admin/orders')->assertOk();
        $large = count(DB::getQueryLog());

        expect($large)->toBe($small);
    });

    // ---------- Show ----------

    it('shows an order with the customer, the items and who changed it', function () {
        [$order, $variant] = codOrderFor($this->customer, 2);

        $this->getJson("/api/admin/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('message', 'Order retrieved successfully.')
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.name', 'Rahim Uddin')
            ->assertJsonPath('data.customer.email', 'rahim@example.com')
            ->assertJsonPath('data.items.0.variant_id', $variant->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.history.0.to', 'pending_confirmation')
            ->assertJsonPath('data.history.0.by.name', 'Rahim Uddin')
            ->assertJsonPath('data.cancelled_by', null)
            ->assertJsonPath('data.available_actions', ['confirm', 'cancel'])
            ->assertJsonMissingPath('data.idempotency_key');
    });

    it('shows the actions that are possible for each status', function (OrderStatus $status, PaymentStatus $payment, array $actions) {
        $order = Order::factory()->create(['status' => $status, 'payment_status' => $payment]);

        $this->getJson("/api/admin/orders/{$order->number}")->assertJsonPath('data.available_actions', $actions);
    })->with([
        'waiting for confirmation' => [OrderStatus::PendingConfirmation, PaymentStatus::Unpaid, ['confirm', 'cancel']],
        'waiting for payment' => [OrderStatus::PendingPayment, PaymentStatus::Unpaid, ['cancel']],
        'confirmed' => [OrderStatus::Confirmed, PaymentStatus::Unpaid, ['ship', 'cancel']],
        'confirmed and paid' => [OrderStatus::Confirmed, PaymentStatus::Paid, ['ship']],
        'shipped' => [OrderStatus::Shipped, PaymentStatus::Unpaid, ['deliver']],
        'delivered' => [OrderStatus::Delivered, PaymentStatus::Paid, []],
        'cancelled' => [OrderStatus::Cancelled, PaymentStatus::Unpaid, []],
    ]);

    it('returns 404 for an order that does not exist', function () {
        $this->getJson('/api/admin/orders/ORD-000101-999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
        $this->postJson('/api/admin/orders/ORD-000101-999999/confirm')->assertNotFound();
        $this->postJson('/api/admin/orders/ORD-000101-999999/cancel', ['reason' => 'Because'])->assertNotFound();
    });

    it('does not show staff fields to the customer', function () {
        [$order] = codOrderFor($this->customer);

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/orders/{$order->number}")
            ->assertOk()
            ->assertJsonMissingPath('data.customer')
            ->assertJsonMissingPath('data.available_actions')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.history.0.by');
    });

    // ---------- Confirm ----------

    it('confirms a cash on delivery order', function () {
        [$order, $variant] = codOrderFor($this->customer, 2);

        $this->postJson("/api/admin/orders/{$order->number}/confirm")
            ->assertOk()
            ->assertJsonPath('message', 'Order confirmed successfully.')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.available_actions', ['ship', 'cancel'])
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.from', 'pending_confirmation')
            ->assertJsonPath('data.history.1.to', 'confirmed')
            ->assertJsonPath('data.history.1.note', 'Confirmed by staff')
            ->assertJsonPath('data.history.1.by.id', $this->staff->id);

        $fresh = $order->fresh();

        expect($fresh->status)->toBe(OrderStatus::Confirmed);
        expect($fresh->confirmed_at)->not->toBeNull();
        expect($fresh->expires_at)->toBeNull();
        expect($variant->fresh()->stock)->toBe(8);
        expect(InventoryMovement::count())->toBe(1);
    });

    it('saves the note given when confirming', function () {
        [$order] = codOrderFor($this->customer);

        $this->postJson("/api/admin/orders/{$order->number}/confirm", ['note' => 'Confirmed by phone at 3pm'])
            ->assertOk()
            ->assertJsonPath('data.history.1.note', 'Confirmed by phone at 3pm');
    });

    it('rejects a note that is too long', function (string $action) {
        [$order] = codOrderFor($this->customer);
        $order->update(['status' => OrderStatus::Confirmed]);

        $this->postJson("/api/admin/orders/{$order->number}/{$action}", ['note' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    })->with(['confirm', 'ship', 'deliver']);

    it('lets the customer see the confirmation', function () {
        [$order] = codOrderFor($this->customer);
        $this->postJson("/api/admin/orders/{$order->number}/confirm")->assertOk();

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/orders/{$order->number}")
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.history.1.to', 'confirmed');
    });

    it('confirms only once', function () {
        [$order] = codOrderFor($this->customer);

        $this->postJson("/api/admin/orders/{$order->number}/confirm")->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only an order that is waiting for confirmation can be confirmed.')
            ->assertJsonPath('data', null);

        expect($order->statusHistory()->count())->toBe(2);
    });

    it('cannot confirm an order in another status', function (OrderStatus $status) {
        $order = Order::factory()->create(['status' => $status]);

        $this->postJson("/api/admin/orders/{$order->number}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only an order that is waiting for confirmation can be confirmed.');

        expect($order->fresh()->status)->toBe($status);
    })->with([OrderStatus::PendingPayment, OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled]);

    it('uses the status in the database when the order object is out of date', function () {
        [$order] = codOrderFor($this->customer);
        $stale = Order::findOrFail($order->id);

        Order::whereKey($order->id)->update(['status' => OrderStatus::Cancelled->value]);

        expect(fn () => app(OrderService::class)->confirm($stale, $this->staff))
            ->toThrow(OrderException::class, 'Only an order that is waiting for confirmation can be confirmed.');

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
        expect($order->statusHistory()->count())->toBe(1);
    });

    // ---------- Ship ----------

    it('ships a confirmed order', function () {
        [$order] = codOrderFor($this->customer);
        $this->postJson("/api/admin/orders/{$order->number}/confirm")->assertOk();

        $this->postJson("/api/admin/orders/{$order->number}/ship", ['note' => 'Sent with Pathao'])
            ->assertOk()
            ->assertJsonPath('message', 'Order marked as shipped.')
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.available_actions', ['deliver'])
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.history.2.from', 'confirmed')
            ->assertJsonPath('data.history.2.to', 'shipped')
            ->assertJsonPath('data.history.2.note', 'Sent with Pathao');

        expect($order->fresh()->shipped_at)->not->toBeNull();
    });

    it('uses a default note when shipping', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

        $this->postJson("/api/admin/orders/{$order->number}/ship")->assertOk();

        expect($order->statusHistory()->latest('id')->first()->note)->toBe('Shipped');
    });

    it('cannot ship an order that is not confirmed', function (OrderStatus $status) {
        $order = Order::factory()->create(['status' => $status]);

        $this->postJson("/api/admin/orders/{$order->number}/ship")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only a confirmed order can be shipped.');

        expect($order->fresh()->status)->toBe($status);
        expect($order->fresh()->shipped_at)->toBeNull();
    })->with([OrderStatus::PendingPayment, OrderStatus::PendingConfirmation, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled]);

    it('ships only once', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

        $this->postJson("/api/admin/orders/{$order->number}/ship")->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/ship")->assertStatus(409);

        expect($order->statusHistory()->count())->toBe(1);
    });

    // ---------- Deliver ----------

    it('delivers a shipped cash on delivery order and marks it paid', function () {
        $order = Order::factory()->create([
            'status' => OrderStatus::Shipped,
            'payment_method' => PaymentMethod::Cod,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        $this->postJson("/api/admin/orders/{$order->number}/deliver")
            ->assertOk()
            ->assertJsonPath('message', 'Order marked as delivered.')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.history.0.note', 'Delivered. Cash collected.');

        expect($order->fresh()->delivered_at)->not->toBeNull();
        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    });

    it('does not change the payment of an order that was paid online or needed no payment', function (PaymentStatus $payment) {
        $order = Order::factory()->create([
            'status' => OrderStatus::Shipped,
            'payment_method' => PaymentMethod::Online,
            'payment_status' => $payment,
        ]);

        $this->postJson("/api/admin/orders/{$order->number}/deliver")
            ->assertOk()
            ->assertJsonPath('data.payment_status', $payment->value)
            ->assertJsonPath('data.history.0.note', 'Delivered');
    })->with([PaymentStatus::Paid, PaymentStatus::NotRequired]);

    it('cannot deliver an order that was not shipped', function (OrderStatus $status) {
        $order = Order::factory()->create(['status' => $status]);

        $this->postJson("/api/admin/orders/{$order->number}/deliver")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only a shipped order can be marked as delivered.');

        expect($order->fresh()->delivered_at)->toBeNull();
    })->with([OrderStatus::PendingPayment, OrderStatus::PendingConfirmation, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled]);

    it('delivers only once, and a delivered order cannot be cancelled', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

        $this->postJson("/api/admin/orders/{$order->number}/deliver")->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/deliver")->assertStatus(409);
        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order can no longer be cancelled.');

        expect($order->statusHistory()->count())->toBe(1);
    });

    // ---------- Cancel ----------

    it('requires a reason to cancel', function (array $body) {
        [$order] = codOrderFor($this->customer);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        expect($order->fresh()->status)->toBe(OrderStatus::PendingConfirmation);
    })->with([
        'missing' => [[]],
        'empty' => [['reason' => '']],
        'too short' => [['reason' => 'ab']],
        'too long' => [['reason' => str_repeat('a', 256)]],
    ]);

    it('cancels a cash on delivery order and puts the stock and the coupon use back', function () {
        makeCoupon(['code' => 'ONCE']);
        [$order, $variant] = codOrderFor($this->customer, 2, 'ONCE');

        expect($variant->fresh()->stock)->toBe(8);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Customer did not answer the phone'])
            ->assertOk()
            ->assertJsonPath('message', 'Order cancelled successfully.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Customer did not answer the phone')
            ->assertJsonPath('data.cancelled_by.id', $this->staff->id)
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.history.1.to', 'cancelled')
            ->assertJsonPath('data.history.1.note', 'Customer did not answer the phone')
            ->assertJsonPath('data.history.1.by.id', $this->staff->id);

        $fresh = $order->fresh();

        expect($fresh->status)->toBe(OrderStatus::Cancelled);
        expect($fresh->cancelled_by)->toBe($this->staff->id);
        expect($fresh->expires_at)->toBeNull();
        expect($variant->fresh()->stock)->toBe(10);
        expect(CouponRedemption::firstOrFail()->status)->toBe(RedemptionStatus::Released);
        expect(InventoryMovement::orderBy('id')->get()->map(fn ($m) => $m->type->value)->all())->toBe(['sale', 'cancellation']);
        expect(InventoryMovement::where('type', 'cancellation')->firstOrFail()->created_by)->toBe($this->staff->id);
    });

    it('cancels an online order that waits for payment and releases the hold', function () {
        $variant = buyable(1000, ['stock' => 10]);
        $order = placeOrder($this->customer, readyCart($this->customer, [[$variant, 2]]), ['payment_method' => 'online']);

        expect($variant->fresh()->reserved)->toBe(2);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Fraud check failed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fresh = $variant->fresh();

        expect($fresh->stock)->toBe(10);
        expect($fresh->reserved)->toBe(0);
        expect(InventoryReservation::firstOrFail()->status)->toBe(ReservationStatus::Released);
        expect(InventoryMovement::count())->toBe(0);
    });

    it('cancels a confirmed order', function () {
        [$order, $variant] = codOrderFor($this->customer, 2);
        $this->postJson("/api/admin/orders/{$order->number}/confirm")->assertOk();

        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Out of delivery area'])
            ->assertOk()
            ->assertJsonPath('data.history.2.from', 'confirmed');

        expect($variant->fresh()->stock)->toBe(10);
    });

    it('cannot cancel an order that was shipped, delivered or cancelled', function (OrderStatus $status) {
        [$order, $variant] = codOrderFor($this->customer, 2);
        $order->update(['status' => $status]);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order can no longer be cancelled.');

        expect($order->fresh()->status)->toBe($status);
        expect($variant->fresh()->stock)->toBe(8);
    })->with([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled]);

    it('cancels a paid order and requests a product refund without delivery', function () {
        Queue::fake([ProcessRefund::class]);
        [$order, $variant] = codOrderFor($this->customer, 2);
        $order->update(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->for($order)->succeeded()->create([
            'amount' => $order->grand_total,
            'paid_amount' => $order->grand_total,
            'provider_reference' => 'BANK-ORDER-CANCEL',
        ]);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", [
            'reason' => 'Customer asked',
            'include_delivery_fee' => false,
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'refund_pending');

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
        expect(Refund::firstOrFail()->amount)->toBe('2000.00');
        expect($variant->fresh()->stock)->toBe(10);
    });

    it('cancels only once and puts the stock back only once', function () {
        [$order, $variant] = codOrderFor($this->customer, 2);

        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'First request'])->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Second request'])->assertStatus(409);

        expect($variant->fresh()->stock)->toBe(10);
        expect(InventoryMovement::where('type', 'cancellation')->count())->toBe(1);
        expect($order->statusHistory()->count())->toBe(2);
    });

    it('shows the customer why the order was cancelled', function () {
        [$order] = codOrderFor($this->customer);
        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Product is out of production'])->assertOk();

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/orders/{$order->number}")
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Product is out of production')
            ->assertJsonPath('data.can_cancel', false);
    });

    it('lets the customer use the coupon again after staff cancelled the order', function () {
        makeCoupon(['code' => 'ONCE']);
        [$order] = codOrderFor($this->customer, 1, 'ONCE');
        $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Customer did not answer'])->assertOk();

        $this->carts = app(CartService::class);
        $this->carts->addItem($this->customer, buyable(1000, ['stock' => 10])->id, 1);

        expect($this->carts->applyCoupon($this->customer, 'ONCE')['coupon']['applied'])->toBeTrue();
    });

    // ---------- The whole journey ----------

    it('follows a cash on delivery order from checkout to delivery', function () {
        [$order, $variant] = codOrderFor($this->customer, 2);

        $this->postJson("/api/admin/orders/{$order->number}/confirm", ['note' => 'Confirmed by phone'])->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/ship", ['note' => 'Pathao #4521'])->assertOk();
        $this->postJson("/api/admin/orders/{$order->number}/deliver")->assertOk();

        $response = $this->getJson("/api/admin/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonCount(4, 'data.history');

        expect($response->json('data.history.*.to'))->toBe(['pending_confirmation', 'confirmed', 'shipped', 'delivered']);
        expect($response->json('data.history.*.note'))->toBe(['Order placed', 'Confirmed by phone', 'Pathao #4521', 'Delivered. Cash collected.']);

        $fresh = $order->fresh();

        expect($fresh->confirmed_at)->not->toBeNull();
        expect($fresh->shipped_at)->not->toBeNull();
        expect($fresh->delivered_at)->not->toBeNull();
        expect($variant->fresh()->stock)->toBe(8);
        expect(InventoryMovement::count())->toBe(1);

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/orders/{$order->number}")
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.can_cancel', false);
    });
});
