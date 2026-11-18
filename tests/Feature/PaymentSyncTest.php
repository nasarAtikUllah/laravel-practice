<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentCallbackService;
use App\Services\Payments\WebhookEvent;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** An online order and its payment, started at the provider. */
function syncPayment(User $user, int $quantity = 2): array
{
    [$order, $variant] = onlineOrderFor($user, $quantity);

    fakeSslcommerzSession();

    return [$order, $variant, app(PaymentService::class)->initiate($order)];
}

/** What the provider answers when we ask about the payment. */
function syncProvider(Payment $payment, string $status = 'VALID', array $overrides = []): void
{
    $body = array_merge([
        'status' => $status,
        'tran_id' => $payment->reference,
        'val_id' => 'VAL'.$payment->id,
        'amount' => $payment->amount,
        'currency_type' => 'BDT',
        'currency' => 'BDT',
        'bank_tran_id' => 'BANK'.$payment->id,
        'risk_level' => '0',
    ], $overrides);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [$body],
        ]),
    ]);
}

function syncSummary(int $checked, int $confirmed = 0, int $flagged = 0, int $closed = 0, int $unchanged = 0, int $errors = 0): string
{
    return "Checked {$checked} payment(s): {$confirmed} confirmed, {$flagged} flagged for refund, {$closed} closed, {$unchanged} unchanged, {$errors} could not be checked.";
}

beforeEach(function () {
    $this->user = User::factory()->create();
    seedShipping();
});

it('confirms a payment that no notification reached', function () {
    [$order, $variant, $payment] = syncPayment($this->user);

    $this->travel(3)->minutes();
    syncProvider($payment);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, confirmed: 1))->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($payment->fresh()->provider_reference)->toBe('BANK'.$payment->id);
    expect($variant->fresh()->stock)->toBe(8);
    expect(PaymentEvent::firstOrFail()->result)->toBe('confirmed');
});

it('does not check a payment that is younger than 2 minutes', function () {
    [, , $payment] = syncPayment($this->user);
    syncProvider($payment);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(0))->assertSuccessful();

    Http::assertNothingSent();
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
});

it('closes an attempt that the provider says failed, and keeps the order', function () {
    [$order, $variant, $payment] = syncPayment($this->user);

    $this->travel(3)->minutes();
    syncProvider($payment, 'FAILED');

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, closed: 1))->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Failed);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($variant->fresh()->reserved)->toBe(2);
});

it('leaves a payment alone when the provider has no record of it', function () {
    [, , $payment] = syncPayment($this->user);

    $this->travel(3)->minutes();
    sslFake(['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []])]);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
    expect(PaymentEvent::count())->toBe(0);
});

it('leaves a payment alone when the provider says it is not finished', function () {
    [, , $payment] = syncPayment($this->user);

    $this->travel(3)->minutes();
    syncProvider($payment, 'PENDING');

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
});

it('flags a payment that arrives for an order that was cancelled', function () {
    [$order, , $payment] = syncPayment($this->user);
    app(OrderService::class)->cancel($order, $this->user);

    $this->travel(3)->minutes();
    syncProvider($payment);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, flagged: 1))->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($payment->fresh()->review_reason)->toBe('order_cancelled');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('checks a closed payment for an hour, and not longer', function () {
    $recent = Payment::factory()->failed()->create(['created_at' => now()->subMinutes(30)]);
    Payment::factory()->failed()->create(['created_at' => now()->subMinutes(90)]);
    syncProvider($recent, 'FAILED');

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();

    Http::assertSentCount(1);
});

it('checks an open payment for 24 hours, and not longer', function () {
    Payment::factory()->create(['created_at' => now()->subHours(23)]);
    Payment::factory()->create(['created_at' => now()->subHours(25)]);
    sslFake(['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []])]);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();
});

it('reads how far back to look from the options', function () {
    Payment::factory()->create(['created_at' => now()->subHours(3)]);
    sslFake(['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []])]);

    $this->artisan('payments:sync --hours=2')->expectsOutput(syncSummary(0))->assertSuccessful();
    $this->artisan('payments:sync --hours=4')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();
});

it('does not check payments that were paid, never reached the provider, or are waiting for a person', function () {
    Payment::factory()->succeeded()->create(['created_at' => now()->subMinutes(10)]);
    Payment::factory()->create(['created_at' => now()->subMinutes(10), 'checkout_url' => null]);
    Payment::factory()->failed()->create(['created_at' => now()->subMinutes(10), 'review_reason' => 'amount_mismatch']);
    sslFake([]);

    $this->artisan('payments:sync')->expectsOutput(syncSummary(0))->assertSuccessful();

    Http::assertNothingSent();
});

it('checks only as many payments as the limit allows, oldest first', function () {
    $old = Payment::factory()->create(['created_at' => now()->subMinutes(20)]);
    Payment::factory()->create(['created_at' => now()->subMinutes(10)]);
    sslFake(['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []])]);

    $this->artisan('payments:sync --limit=1')->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'tran_id='.$old->reference));
});

it('checks one payment by its reference, even a young one', function () {
    [$order, , $payment] = syncPayment($this->user);
    syncProvider($payment);

    $this->artisan('payments:sync '.$payment->reference)->expectsOutput(syncSummary(1, confirmed: 1))->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('reports a payment that does not exist', function () {
    $this->artisan('payments:sync P999-ABCDEFGHIJ')->expectsOutput('Payment not found.')->assertFailed();
});

it('does not ask the provider about a payment that was paid already', function () {
    [$order, , $payment] = syncPayment($this->user);
    syncProvider($payment);
    $this->artisan('payments:sync '.$payment->reference)->assertSuccessful();

    sslFake([]);

    $this->artisan('payments:sync '.$payment->reference)->expectsOutput(syncSummary(1, unchanged: 1))->assertSuccessful();

    Http::assertNothingSent();
});

it('does not confirm twice when a notification and the sync report the same payment', function () {
    [, $variant, $payment] = syncPayment($this->user);
    $this->travel(3)->minutes();
    syncProvider($payment);

    $this->artisan('payments:sync')->assertSuccessful();
    // A late copy of the same event is recognized.
    $event = PaymentEvent::firstOrFail();

    expect(app(PaymentCallbackService::class)->apply($payment->fresh(), new WebhookEvent(
        eventId: $event->event_id,
        type: 'succeeded',
        reference: $payment->reference,
        providerReference: null,
        amount: $payment->amount,
        currency: 'BDT',
        reason: null,
        payload: [],
    )))->toBe('duplicate');

    expect($variant->fresh()->stock)->toBe(8);
    expect(PaymentEvent::count())->toBe(1);
});

it('continues after a payment that could not be checked, and exits with an error', function () {
    [$order, , $first] = syncPayment($this->user);
    $second = Payment::factory()->create(['created_at' => now()->subMinutes(10)]);
    $this->travel(3)->minutes();

    sslFake(function ($request) use ($first) {
        if (str_contains($request->url(), 'tran_id='.$first->reference)) {
            throw new ConnectionException('cURL error for '.$request->url());
        }

        return Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]);
    });

    $this->artisan('payments:sync')
        ->expectsOutput("Payment {$first->reference} could not be checked.")
        ->expectsOutput(syncSummary(2, unchanged: 1, errors: 1))
        ->assertFailed();

    expect($first->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
});

it('never writes the store password in its output', function () {
    [, , $payment] = syncPayment($this->user);
    $this->travel(3)->minutes();
    sslFake(fn () => throw new ConnectionException('cURL error for https://x.test?store_passwd=teststore@ssl'));

    $this->artisan('payments:sync')
        ->doesntExpectOutputToContain('teststore')
        ->assertFailed();
});
