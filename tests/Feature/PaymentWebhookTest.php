<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Payments\GatewayException;
use App\Services\Payments\InvalidWebhookException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\SslCommerzGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** The message SSLCommerz posts to us, signed the way SSLCommerz signs it. */
function hookRequest(Payment $payment, array $overrides = [], bool $signed = true): Request
{
    $fields = array_merge([
        'status' => 'VALID',
        'tran_id' => $payment->reference,
        'val_id' => 'VAL'.$payment->id.'0001',
        'amount' => $payment->amount,
        'currency' => 'BDT',
        'bank_tran_id' => 'BANK'.$payment->id.'0001',
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $overrides);

    /** @var SslCommerzGateway $gateway */
    $gateway = app(PaymentGatewayManager::class)->driver('sslcommerz');

    return Request::create('/api/payments/sslcommerz/ipn', 'POST', $signed ? $gateway->sign($fields) : $fields);
}

/** What SSLCommerz answers when we ask about this payment. Any other request fails the test. */
function hookAnswers(Payment $payment, array $validation = [], ?array $lookup = null): void
{
    $body = array_merge([
        'status' => 'VALID',
        'tran_id' => $payment->reference,
        'val_id' => 'VAL'.$payment->id.'0001',
        'amount' => $payment->amount,
        'currency' => 'BDT',
        'currency_type' => 'BDT',
        'currency_amount' => $payment->amount,
        'bank_tran_id' => 'BANK'.$payment->id.'0001',
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $validation);

    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response($body),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(
            $lookup ?? ['APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [$body]]
        ),
    ]);
}

function hookHandle(Payment $payment, array $overrides = [], bool $signed = true): array
{
    return app(PaymentService::class)->handleWebhook('sslcommerz', hookRequest($payment, $overrides, $signed), $signed);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    seedShipping();
    fakeSslcommerzSession();

    [$this->order, $this->variant] = onlineOrderFor($this->user, 2);
    $this->payment = app(PaymentService::class)->initiate($this->order);
});

// ---------- A good payment ----------

it('confirms the order from a signed IPN after asking SSLCommerz', function () {
    hookAnswers($this->payment);

    [$payment, $result] = hookHandle($this->payment);

    expect($result)->toBe('confirmed');
    expect($payment->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($this->order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    expect($this->variant->fresh()->stock)->toBe(8);
    expect($this->variant->fresh()->reserved)->toBe(0);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'validationserverAPI.php')
        && str_contains($request->url(), 'val_id=VAL'.$this->payment->id.'0001'));
});

it('stores what happened, without the card number or the signature', function () {
    hookAnswers($this->payment);

    hookHandle($this->payment);

    $event = PaymentEvent::firstOrFail();

    expect($event->payment_id)->toBe($this->payment->id);
    expect($event->provider)->toBe('sslcommerz');
    expect($event->type)->toBe('succeeded');
    expect($event->result)->toBe('confirmed');
    expect($event->payload['bank_tran_id'])->toBe('BANK'.$this->payment->id.'0001');
    expect($event->payload)->not->toHaveKeys(['card_no', 'verify_sign', 'verify_key']);
});

it('does not trust the amount in the message, only the answer of SSLCommerz', function () {
    hookAnswers($this->payment, ['amount' => '10.00', 'currency_amount' => '10.00']);

    [$payment, $result] = hookHandle($this->payment, ['amount' => '2060.00']);

    expect($result)->toBe('amount_mismatch');
    expect($payment->review_reason)->toBe('amount_mismatch');
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($this->variant->fresh()->reserved)->toBe(2);
});

// ---------- The same fact twice ----------

it('handles the same IPN once, however often it is sent', function () {
    hookAnswers($this->payment);

    expect(hookHandle($this->payment)[1])->toBe('confirmed');

    [$payment, $result] = hookHandle($this->payment);

    expect($result)->toBe('duplicate_event');
    expect($payment->status)->toBe(PaymentRecordStatus::Succeeded);
    expect(PaymentEvent::count())->toBe(1);
    expect(InventoryMovement::count())->toBe(1);
    expect($this->variant->fresh()->stock)->toBe(8);
});

it('handles the IPN and the customer coming back as one fact', function () {
    hookAnswers($this->payment);

    expect(hookHandle($this->payment)[1])->toBe('confirmed');

    // The customer's browser comes back. It is not signed.
    [$payment, $result] = hookHandle($this->payment, signed: false);

    expect($result)->toBe('duplicate_event');
    expect($payment->status)->toBe(PaymentRecordStatus::Succeeded);
    expect(PaymentEvent::count())->toBe(1);
    expect(InventoryMovement::count())->toBe(1);
});

it('lets the customer coming back confirm the order when the IPN never arrived', function () {
    hookAnswers($this->payment);

    [, $result] = hookHandle($this->payment, signed: false);

    expect($result)->toBe('confirmed');
    expect($this->order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('handles a message again when the first try stopped before it finished', function () {
    hookAnswers($this->payment);

    // What a crash after saving the event leaves behind: the event, with no result.
    PaymentEvent::create([
        'payment_id' => $this->payment->id,
        'provider' => 'sslcommerz',
        'event_id' => hash('sha256', 'succeeded:'.$this->payment->reference.':VAL'.$this->payment->id.'0001'),
        'type' => 'succeeded',
        'payload' => [],
    ]);

    expect(hookHandle($this->payment)[1])->toBe('confirmed');

    expect(PaymentEvent::count())->toBe(1);
    expect(PaymentEvent::firstOrFail()->result)->toBe('confirmed');
});

// ---------- Messages that must not get through ----------

it('refuses an unsigned IPN even when SSLCommerz would confirm the payment', function () {
    hookAnswers($this->payment);

    expect(fn () => hookHandle($this->payment, signed: false) )->not->toThrow(InvalidWebhookException::class);
});

it('refuses an unsigned IPN and leaves the order alone', function () {
    sslFake([]);

    expect(fn () => app(PaymentService::class)->handleWebhook('sslcommerz', hookRequest($this->payment, [], false), true))
        ->toThrow(InvalidWebhookException::class, 'The notification signature is not valid.');

    Http::assertNothingSent();
    expect(PaymentEvent::count())->toBe(0);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('refuses a message for a payment we do not have, without asking SSLCommerz', function () {
    sslFake([]);
    $ghost = new Payment(['reference' => 'P999-NOTREALREF', 'amount' => '2060.00']);

    expect(fn () => hookHandle($ghost))->toThrow(PaymentException::class, 'Payment not found.');

    Http::assertNothingSent();
    expect(PaymentEvent::count())->toBe(0);
});

it('refuses a message without a transaction id', function () {
    sslFake([]);
    $request = Request::create('/api/payments/sslcommerz/ipn', 'POST', ['status' => 'VALID']);

    expect(fn () => app(PaymentService::class)->handleWebhook('sslcommerz', $request))
        ->toThrow(PaymentException::class, 'Payment not found.');

    Http::assertNothingSent();
});

it('keeps everything as it was when SSLCommerz cannot be reached, and works when the message is sent again', function () {
    sslFake(['*/validator/api/validationserverAPI.php*' => Http::response('error', 500)]);

    expect(fn () => hookHandle($this->payment))->toThrow(GatewayException::class);

    expect(PaymentEvent::count())->toBe(0);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($this->variant->fresh()->reserved)->toBe(2);

    hookAnswers($this->payment);

    expect(hookHandle($this->payment)[1])->toBe('confirmed');
});

// ---------- A payment that did not go through ----------

it('closes the attempt when SSLCommerz says it failed, and keeps the hold', function () {
    hookAnswers($this->payment, lookup: [
        'APIConnect' => 'DONE',
        'no_of_trans_found' => 1,
        'element' => [['status' => 'FAILED', 'tran_id' => $this->payment->reference, 'error' => 'Card declined']],
    ]);

    [$payment, $result] = hookHandle($this->payment, ['status' => 'FAILED', 'val_id' => null]);

    expect($result)->toBe('failed');
    expect($payment->status)->toBe(PaymentRecordStatus::Failed);
    expect($payment->failure_reason)->toBe('Card declined');
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($this->variant->fresh()->reserved)->toBe(2);
    expect(PaymentEvent::firstOrFail()->result)->toBe('failed');
});

it('marks the attempt cancelled or expired', function (string $status, PaymentRecordStatus $expected, string $reason) {
    hookAnswers($this->payment, lookup: [
        'APIConnect' => 'DONE',
        'no_of_trans_found' => 1,
        'element' => [['status' => $status, 'tran_id' => $this->payment->reference]],
    ]);

    [$payment, $result] = hookHandle($this->payment, ['status' => $status, 'val_id' => null]);

    expect($result)->toBe($expected->value);
    expect($payment->status)->toBe($expected);
    expect($payment->failure_reason)->toBe($reason);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
})->with([
    ['CANCELLED', PaymentRecordStatus::Cancelled, 'The payment was cancelled.'],
    ['EXPIRED', PaymentRecordStatus::Expired, 'The payment session expired.'],
]);

it('ignores a payment that SSLCommerz knows nothing about yet', function () {
    hookAnswers($this->payment, lookup: ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]);

    [$payment, $result] = hookHandle($this->payment, ['val_id' => null]);

    expect($result)->toBe('ignored');
    expect($payment->status)->toBe(PaymentRecordStatus::Initiated);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect(PaymentEvent::firstOrFail()->result)->toBe('ignored');
});