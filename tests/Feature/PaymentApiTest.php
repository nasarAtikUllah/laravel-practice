<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on the payment routes', function () {
    $this->postJson('/api/orders/ORD-000101-999999/pay')->assertUnauthorized();
    $this->getJson('/api/orders/ORD-000101-999999/payments')->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        seedShipping();
        fakeSslcommerzSession();
    });

    // ---------- Pay ----------

    it('starts a payment for an online order', function () {
        [$order] = onlineOrderFor($this->user, 2);

        $response = $this->postJson("/api/orders/{$order->number}/pay")
            ->assertCreated()
            ->assertJsonPath('message', 'Payment started.')
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.payment.amount', '2060.00')
            ->assertJsonPath('data.payment.currency', 'BDT')
            ->assertJsonPath('data.payment.checkout_url', 'https://sandbox.sslcommerz.com/EasyCheckOut/SESSION-KEY-1')
            ->assertJsonPath('data.payment.refund_pending', false)
            ->assertJsonPath('data.attempts_used', 1)
            ->assertJsonPath('data.attempts_left', 2)
            ->assertJsonMissingPath('data.payment.session_key')
            ->assertJsonMissingPath('data.payment.provider_reference')
            ->assertJsonMissingPath('data.payment.validation_id')
            ->assertJsonMissingPath('data.payment.risk_level')
            ->assertJsonMissingPath('data.payment.review_reason');

        expect($response->json('data.payment.reference'))->toMatch('/^P\d+-[0-9A-Z]{10}$/');
    });

    it('gives the same open payment back and asks the provider only once', function () {
        [$order] = onlineOrderFor($this->user);

        $first = $this->postJson("/api/orders/{$order->number}/pay")->assertCreated()->json('data.payment');
        $second = $this->postJson("/api/orders/{$order->number}/pay")
            ->assertOk()
            ->assertJsonPath('message', 'Payment already started.')
            ->assertJsonPath('data.attempts_used', 1)
            ->json('data.payment');

        expect($second['reference'])->toBe($first['reference']);
        expect($second['checkout_url'])->toBe($first['checkout_url']);

        Http::assertSentCount(1);
    });

    it('lets the customer try again after a failed attempt, up to 3 attempts', function () {
        [$order] = onlineOrderFor($this->user);

        foreach ([2, 1, 0] as $left) {
            $reference = $this->postJson("/api/orders/{$order->number}/pay")
                ->assertCreated()
                ->assertJsonPath('data.attempts_left', $left)
                ->json('data.payment.reference');

            app(PaymentService::class)->applyFailed($reference, 'Card declined');
        }

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Too many payment attempts for this order. Please contact us.')
            ->assertJsonPath('data', null);

        expect(Payment::count())->toBe(3);
    });

    it('refuses an order that is paid on delivery', function () {
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order is paid on delivery.');
    });

    it('refuses an order that is not waiting for payment', function (OrderStatus $status) {
        [$order] = onlineOrderFor($this->user);
        $order->update(['status' => $status]);

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order is not waiting for payment.');
    })->with([OrderStatus::Confirmed, OrderStatus::Cancelled]);

    it('refuses to pay after the time to pay ended', function () {
        [$order] = onlineOrderFor($this->user);

        $this->travel(16)->minutes();

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The time to pay for this order has ended. Please place a new order.');
    });

    it('answers 503 when the provider is down, and lets the customer try again', function () {
        [$order] = onlineOrderFor($this->user);
        sslFake(fn () => throw new ConnectionException('cURL error'));

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Payment is not available right now. Please try again in a moment.');

        fakeSslcommerzSession('SESSION-RETRY');

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertCreated()
            ->assertJsonPath('data.attempts_used', 2)
            ->assertJsonPath('data.payment.checkout_url', 'https://sandbox.sslcommerz.com/EasyCheckOut/SESSION-RETRY');
    });

    it('does not let a customer pay for the order of another customer', function () {
        $other = User::factory()->create();
        [$order] = onlineOrderFor($other);

        $this->postJson("/api/orders/{$order->number}/pay")
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');

        expect(Payment::count())->toBe(0);
    });

    it('returns 404 for an order that does not exist or a bad number', function (string $number) {
        $this->postJson("/api/orders/{$number}/pay")->assertNotFound();
        $this->getJson("/api/orders/{$number}/payments")->assertNotFound();
    })->with(['ORD-000101-999999', 'not-a-number', 'ORD-2026-000001']);

    it('limits payment requests to 10 per minute', function () {
        $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));

        foreach (range(1, 10) as $i) {
            $this->postJson("/api/orders/{$order->number}/pay")->assertStatus(422);
        }

        $this->postJson("/api/orders/{$order->number}/pay")->assertStatus(429);
    });

    // ---------- List ----------

    it('lists the attempts of an order, newest first, and gives an address only for the open one', function () {
        [$order] = onlineOrderFor($this->user);
        $first = app(PaymentService::class)->initiate($order);
        app(PaymentService::class)->applyFailed($first->reference, 'Card declined');
        $second = app(PaymentService::class)->initiate($order);

        $this->getJson("/api/orders/{$order->number}/payments")
            ->assertOk()
            ->assertJsonPath('message', 'Payments retrieved successfully.')
            ->assertJsonPath('data.attempts_used', 2)
            ->assertJsonPath('data.attempts_left', 1)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.reference', $second->reference)
            ->assertJsonPath('data.payments.0.status', 'initiated')
            ->assertJsonPath('data.payments.0.checkout_url', $second->checkout_url)
            ->assertJsonPath('data.payments.1.reference', $first->reference)
            ->assertJsonPath('data.payments.1.status', 'failed')
            ->assertJsonPath('data.payments.1.failure_reason', 'Card declined')
            ->assertJsonPath('data.payments.1.checkout_url', null)
            ->assertJsonMissingPath('data.payments.0.provider_reference')
            ->assertJsonMissingPath('data.payments.0.session_key');
    });

    it('gives no address once the time to pay ended', function () {
        [$order] = onlineOrderFor($this->user);
        app(PaymentService::class)->initiate($order);

        $this->travel(16)->minutes();

        $this->getJson("/api/orders/{$order->number}/payments")->assertJsonPath('data.payments.0.checkout_url', null);
    });

    it('shows that a refund is pending without the internal reason', function () {
        [$order] = onlineOrderFor($this->user);
        Payment::factory()->for($order)->succeeded()->create(['amount' => $order->grand_total, 'review_reason' => 'late_payment']);

        $this->getJson("/api/orders/{$order->number}/payments")
            ->assertJsonPath('data.payments.0.status', 'succeeded')
            ->assertJsonPath('data.payments.0.refund_pending', true)
            ->assertJsonMissingPath('data.payments.0.review_reason');
    });

    it('explains a payment that could not be started in plain words', function () {
        [$order] = onlineOrderFor($this->user);
        Payment::factory()->for($order)->failed()->create(['amount' => $order->grand_total, 'failure_reason' => 'gateway_error']);

        $this->getJson("/api/orders/{$order->number}/payments")
            ->assertJsonPath('data.payments.0.failure_reason', 'The payment could not be started.');
    });

    it('shows an empty list for an order without payments', function () {
        [$order] = onlineOrderFor($this->user);

        $this->getJson("/api/orders/{$order->number}/payments")
            ->assertOk()
            ->assertJsonPath('data.attempts_used', 0)
            ->assertJsonPath('data.attempts_left', 3)
            ->assertJsonPath('data.payments', []);
    });

    it('does not show the payments of another customer', function () {
        $other = Order::factory()->create();
        Payment::factory()->for($other)->create();

        $this->getJson("/api/orders/{$other->number}/payments")->assertNotFound();
    });
});
