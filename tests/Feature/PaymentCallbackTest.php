<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InventoryService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\SslCommerzGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function cbStart(Order $order): Payment
{
    fakeSslcommerzSession();

    return app(PaymentService::class)->initiate($order);
}

/** Adds the signature fields the way SSLCommerz does. */
function cbSign(array $fields): array
{
    /** @var SslCommerzGateway $gateway */
    $gateway = app(PaymentGatewayManager::class)->driver('sslcommerz');

    return $gateway->sign($fields);
}

/** What SSLCommerz posts to us. The values in it are never used. */
function cbFields(Payment $payment, array $overrides = []): array
{
    return array_merge([
        'status' => 'VALID',
        'tran_date' => '2026-11-18 10:00:00',
        'tran_id' => $payment->reference,
        'val_id' => 'VAL'.$payment->id,
        'amount' => $payment->amount,
        'store_amount' => '1.00',
        'currency' => 'BDT',
        'bank_tran_id' => 'BANK'.$payment->id,
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $overrides);
}

function cbIpn(Payment $payment, array $overrides = []): array
{
    return cbSign(cbFields($payment, $overrides));
}

function cbPostIpn(object $test, array $fields)
{
    return $test->post('/api/payments/sslcommerz/ipn', $fields, ['Accept' => 'application/json']);
}

/** What SSLCommerz answers when we ask. Only a good status is accepted by the validation API. */
function cbProvider(Payment $payment, string $status = 'VALID', array $overrides = []): void
{
    $body = array_merge([
        'status' => $status,
        'tran_id' => $payment->reference,
        'val_id' => 'VAL'.$payment->id,
        'amount' => $payment->amount,
        'currency_type' => 'BDT',
        'currency' => 'BDT',
        'bank_tran_id' => 'BANK'.$payment->id,
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $overrides);

    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response(
            in_array($status, ['VALID', 'VALIDATED'], true) ? $body : ['status' => 'INVALID_TRANSACTION']
        ),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [$body],
        ]),
    ]);
}

/** SSLCommerz has no record of the payment. */
function cbProviderKnowsNothing(): void
{
    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response(['status' => 'INVALID_TRANSACTION']),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]),
    ]);
}

function cbReturn(?Order $order, string $result, string $base = 'https://shop.test/payment/result'): string
{
    if ($order !== null) {
        $base = rtrim((string) config('payments.frontend_order_url'), '/').'/'.rawurlencode($order->number);
    }

    $query = array_filter(['order' => $order?->number, 'result' => $result]);

    return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($query);
}

/** The customer's browser comes back from SSLCommerz. */
function cbBrowser(object $test, string $route, Payment $payment, array $extra = [])
{
    return $test->post("/api/payments/sslcommerz/{$route}", array_merge([
        'tran_id' => $payment->reference,
        'status' => 'VALID',
        'val_id' => 'VAL'.$payment->id,
        'amount' => $payment->amount,
    ], $extra));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    seedShipping();
});

// ---------- IPN: success ----------

it('confirms the order when SSLCommerz says the payment is valid', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    cbPostIpn($this, cbIpn($payment))
        ->assertOk()
        ->assertJsonPath('message', 'Notification processed.')
        ->assertJsonPath('data.result', 'confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($payment->fresh()->provider_reference)->toBe('BANK'.$payment->id);
    expect($variant->fresh()->stock)->toBe(8);
    expect($variant->fresh()->reserved)->toBe(0);

    $event = PaymentEvent::firstOrFail();

    expect($event->payment_id)->toBe($payment->id);
    expect($event->provider)->toBe('sslcommerz');
    expect($event->type)->toBe('succeeded');
    expect($event->result)->toBe('confirmed');
    expect($event->payload)->not->toHaveKeys(['card_no', 'verify_sign', 'verify_key']);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'validationserverAPI.php')
        && str_contains($request->url(), 'val_id=VAL'.$payment->id));
});

it('uses the answer of SSLCommerz and not the amount in the notification', function () {
    [$order] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment); // the provider says 2060.00

    cbPostIpn($this, cbIpn($payment, ['amount' => '1.00']))->assertOk()->assertJsonPath('data.result', 'confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('does not confirm the order when the provider says another amount, although the notification says the right one', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment, 'VALID', ['amount' => '1.00']);

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'amount_mismatch');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Failed);
    expect($payment->fresh()->review_reason)->toBe('amount_mismatch');
    expect($payment->fresh()->provider_reference)->toBe('BANK'.$payment->id);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($variant->fresh()->reserved)->toBe(2);
});

it('processes the same notification only once', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'confirmed');
    cbPostIpn($this, cbIpn($payment))
        ->assertOk()
        ->assertJsonPath('message', 'Notification already processed.')
        ->assertJsonPath('data.result', 'duplicate');

    expect(PaymentEvent::count())->toBe(1);
    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);
    expect($order->statusHistory()->count())->toBe(2);
});

it('treats VALID and VALIDATED as the same event', function () {
    [$order] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);

    cbProvider($payment, 'VALID');
    cbPostIpn($this, cbIpn($payment))->assertJsonPath('data.result', 'confirmed');

    cbProvider($payment, 'VALIDATED');
    cbPostIpn($this, cbIpn($payment))->assertJsonPath('data.result', 'duplicate');

    expect(PaymentEvent::count())->toBe(1);
});

// ---------- IPN: failure, cancel, expiry, nothing ----------

it('closes the attempt when SSLCommerz says the payment failed, and keeps the order', function (string $status, string $outcome, PaymentRecordStatus $expected) {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment, $status, ['error' => 'Card declined']);

    cbPostIpn($this, cbIpn($payment, ['status' => $status]))->assertOk()->assertJsonPath('data.result', $outcome);

    expect($payment->fresh()->status)->toBe($expected);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($variant->fresh()->reserved)->toBe(2);

    cbPostIpn($this, cbIpn($payment, ['status' => $status]))->assertJsonPath('data.result', 'duplicate');

    expect(PaymentEvent::count())->toBe(1);
})->with([
    'failed' => ['FAILED', 'failed', PaymentRecordStatus::Failed],
    'cancelled' => ['CANCELLED', 'cancelled', PaymentRecordStatus::Cancelled],
    'expired' => ['EXPIRED', 'expired', PaymentRecordStatus::Expired],
]);

it('saves the reason of the provider for a failed payment', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'FAILED', ['error' => 'Insufficient balance']);

    cbPostIpn($this, cbIpn($payment))->assertOk();

    expect($payment->fresh()->failure_reason)->toBe('Insufficient balance');
});

it('does nothing but remember the event when the provider says the payment is not finished', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'PENDING');

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'ignored');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
    expect(PaymentEvent::firstOrFail()->type)->toBe('other');
});

it('does nothing when the provider has no record of the payment', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProviderKnowsNothing();

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'ignored');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('flags a payment that arrives after the hold was given back', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    $this->travel(16)->minutes();
    app(InventoryService::class)->releaseExpired();
    app(CouponService::class)->releaseExpired();

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'late_payment');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($payment->fresh()->review_reason)->toBe('late_payment');
    expect($variant->fresh()->stock)->toBe(10);
});

// ---------- IPN: refused messages ----------

it('refuses a notification with a wrong or missing signature without asking the provider', function (Closure $tamper) {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    sslFake([]);

    cbPostIpn($this, $tamper(cbIpn($payment), $payment))
        ->assertStatus(401)
        ->assertJsonPath('data', null);

    Http::assertNothingSent();
    expect(PaymentEvent::count())->toBe(0);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
})->with([
    'no signature' => [fn (array $fields) => array_diff_key($fields, ['verify_sign' => 1, 'verify_key' => 1])],
    'wrong signature' => [fn (array $fields) => ['verify_sign' => str_repeat('0', 32)] + $fields],
    'changed field' => [fn (array $fields) => ['val_id' => 'SOMETHING-ELSE'] + $fields],
]);

it('accepts an unsigned notification only when the signature check is turned off, and still asks the provider', function () {
    config(['payments.gateways.sslcommerz.verify_signature' => false]);
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    cbPostIpn($this, cbFields($payment))->assertOk()->assertJsonPath('data.result', 'confirmed');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'validationserverAPI.php'));
});

it('refuses a notification without a transaction id', function (mixed $tranId) {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    sslFake([]);
    $fields = cbFields($payment);

    if ($tranId === null) {
        unset($fields['tran_id']);
    } else {
        $fields['tran_id'] = $tranId;
    }

    cbPostIpn($this, cbSign($fields))
        ->assertStatus(422)
        ->assertJsonPath('message', 'The notification is not valid: tran_id is missing or not valid.');

    Http::assertNothingSent();
})->with(['missing' => [null], 'empty' => [''], 'too long' => [str_repeat('A', 41)]]);

it('does not ask the provider about a payment we did not start', function () {
    $payment = Payment::factory()->make(['reference' => 'P999-ABCDEFGHIJ', 'amount' => 100]);
    sslFake([]);

    cbPostIpn($this, cbIpn($payment))
        ->assertNotFound()
        ->assertJsonPath('message', 'Payment not found.');

    Http::assertNothingSent();
    expect(PaymentEvent::count())->toBe(0);
});

it('refuses when the provider answers for another transaction', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'VALID', ['tran_id' => 'P9-SOMEBODYELSE']);

    cbPostIpn($this, cbIpn($payment))->assertStatus(422);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect(PaymentEvent::count())->toBe(0);
});

it('answers 503 when the provider cannot be reached, saves nothing, and works on the next try', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    sslFake(fn () => throw new ConnectionException('cURL error for https://x.test?store_passwd=teststore@ssl'));

    cbPostIpn($this, cbIpn($payment))
        ->assertStatus(503)
        ->assertJsonPath('message', 'The payment could not be checked right now. Please try again.');

    expect(PaymentEvent::count())->toBe(0);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);

    cbProvider($payment);

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'confirmed');

    expect($variant->fresh()->stock)->toBe(8);
});

it('saves nothing when acting on a notification fails, so the next one can try again', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    // The first call fails and the next ones do the real work. The router keeps its controller (and so
    // this mock) between two requests of one test, which is why the mock cannot simply be removed.
    $real = app(PaymentService::class);
    $calls = 0;

    $this->partialMock(PaymentService::class, function (MockInterface $mock) use ($real, &$calls) {
        $mock->shouldReceive('applySucceeded')->atLeast()->once()->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('Database is down');
            }

            return $real->applySucceeded(...$args);
        });
    });

    cbPostIpn($this, cbIpn($payment))->assertStatus(500);

    expect(PaymentEvent::count())->toBe(0);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'confirmed');

    expect(PaymentEvent::count())->toBe(1);
    expect($variant->fresh()->stock)->toBe(8);
});

// ---------- Limits ----------

it('limits how often one payment can be checked, and does not call the provider after that', function () {
    config(['payments.callback_attempts_per_reference' => 3]);
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    foreach (range(1, 3) as $i) {
        cbPostIpn($this, cbIpn($payment))->assertOk();
    }

    cbPostIpn($this, cbIpn($payment))
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    Http::assertSentCount(3);
});

it('limits the notifications from one address', function () {
    config(['payments.callback_requests_per_minute' => 2]);
    $payment = Payment::factory()->make(['reference' => 'P999-ABCDEFGHIJ']);
    sslFake([]);

    cbPostIpn($this, cbIpn($payment))->assertNotFound();
    cbPostIpn($this, cbIpn($payment))->assertNotFound();
    cbPostIpn($this, cbIpn($payment))->assertStatus(429);
});

// ---------- The customer comes back ----------

it('confirms the order and sends the customer to the shop when SSLCommerz says the payment is valid', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    cbBrowser($this, 'success', $payment)
        ->assertStatus(303)
        ->assertRedirect(cbReturn($order, 'success'));

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($variant->fresh()->stock)->toBe(8);
});

it('also reads the customer coming back with a GET request', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    $this->get('/api/payments/sslcommerz/success?tran_id='.$payment->reference.'&val_id=VAL'.$payment->id)
        ->assertRedirect(cbReturn($order, 'success'));
});

it('processes a payment once when both the provider and the customer report it', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = cbStart($order);
    cbProvider($payment);

    cbPostIpn($this, cbIpn($payment))->assertJsonPath('data.result', 'confirmed');
    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'success'));

    expect(PaymentEvent::count())->toBe(1);
    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);
});

it('does not believe a customer who says the payment worked when the provider says it failed', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'FAILED');

    cbBrowser($this, 'success', $payment, ['status' => 'VALID'])->assertRedirect(cbReturn($order, 'failed'));

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Failed);
});

it('sends the customer back with the result the provider confirms', function (string $route, string $status, string $result) {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, $status);

    cbBrowser($this, $route, $payment)->assertRedirect(cbReturn($order, $result));
})->with([
    'fail' => ['fail', 'FAILED', 'failed'],
    'cancel' => ['cancel', 'CANCELLED', 'cancelled'],
    'expired' => ['fail', 'EXPIRED', 'expired'],
    'not finished' => ['cancel', 'PENDING', 'pending'],
]);

it('keeps the attempt open when the customer cancels before the provider has any record', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProviderKnowsNothing();

    cbBrowser($this, 'cancel', $payment, ['status' => 'CANCELLED'])->assertRedirect(cbReturn($order, 'pending'));

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
});

it('tells the customer when the money will be refunded', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'VALID', ['amount' => '1.00']);

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'refund_pending'));
});

it('tells the customer when a late payment will be refunded', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    $this->travel(16)->minutes();
    app(InventoryService::class)->releaseExpired();
    app(CouponService::class)->releaseExpired();

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'refund_pending'));
});

it('shows a payment that was paid already as a success', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);
    cbPostIpn($this, cbIpn($payment))->assertOk();

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'success'));
});

it('sends the customer back with unknown for a payment we did not start, without asking the provider', function (array $fields) {
    sslFake([]);

    $this->post('/api/payments/sslcommerz/success', $fields)->assertRedirect(cbReturn(null, 'unknown'));

    Http::assertNothingSent();
})->with([
    'unknown payment' => [['tran_id' => 'P999-ABCDEFGHIJ']],
    'no transaction id' => [[]],
    'too long' => [['tran_id' => str_repeat('A', 41)]],
]);

it('leaves everything as it is when the notification of the customer has a wrong signature', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    sslFake([]);

    cbBrowser($this, 'success', $payment, ['verify_key' => 'tran_id,amount', 'verify_sign' => 'abc'])
        ->assertRedirect(cbReturn($order, 'pending'));

    Http::assertNothingSent();
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
    expect(PaymentEvent::count())->toBe(0);
});

it('sends the customer back with pending when the provider cannot be reached', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    sslFake(fn () => throw new ConnectionException('cURL error'));

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'pending'));

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
});

it('sends the customer back with pending when too many checks were made', function () {
    config(['payments.callback_attempts_per_reference' => 1]);
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment, 'PENDING');

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'pending'));

    $afterFirst = count(Http::recorded());

    expect($afterFirst)->toBeGreaterThan(0); // the first return did ask the provider

    cbBrowser($this, 'success', $payment)->assertRedirect(cbReturn($order, 'pending'));

    Http::assertSentCount($afterFirst); // the throttled second return asks the provider nothing
});

it('takes the address of the shop from the config', function () {
    config(['payments.return_url' => 'https://app.test/paid?lang=bn']);
    config(['payments.frontend_order_url' => 'https://app.test/orders']);
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    cbBrowser($this, 'success', $payment)
        ->assertRedirect('https://app.test/orders/'.$order->number.'?order='.$order->number.'&result=success');
});

it('returns a successful payment to the configured customer order page', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    cbBrowser($this, 'success', $payment)
        ->assertRedirect(cbReturn($order, 'success'));

    $this->get("/my-orders/{$order->number}")
        ->assertOk()
        ->assertSee($order->number);
});

it('shows no payment details in the redirect', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = cbStart($order);
    cbProvider($payment);

    $location = cbBrowser($this, 'success', $payment)->headers->get('Location');

    expect($location)->not->toContain($payment->reference);
    expect($location)->not->toContain('BANK');
    expect($location)->not->toContain('VAL');
});

// ---------- The whole journey ----------

it('follows an online order from the cart to a paid order', function () {
    Sanctum::actingAs($this->user);
    $variant = buyable(1000, ['stock' => 10]);

    $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();
    $hash = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

    $number = $this->postJson('/api/orders', checkoutInput(['cart_hash' => $hash, 'payment_method' => 'online']), ['Idempotency-Key' => 'journey-key-0001'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_payment')
        ->json('data.number');

    fakeSslcommerzSession();

    $paymentData = $this->postJson("/api/orders/{$number}/pay")->assertCreated()->json('data.payment');
    $payment = Payment::where('reference', $paymentData['reference'])->firstOrFail();

    cbProvider($payment);
    cbPostIpn($this, cbIpn($payment))->assertOk()->assertJsonPath('data.result', 'confirmed');

    $this->getJson("/api/orders/{$number}")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.requires_payment', false);

    $this->getJson("/api/orders/{$number}/payments")
        ->assertOk()
        ->assertJsonPath('data.payments.0.status', 'succeeded')
        ->assertJsonPath('data.payments.0.checkout_url', null);

    expect($variant->fresh()->stock)->toBe(8);
});
