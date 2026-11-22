<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\PaymentException;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\Payments\WebhookEvent;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function paymentService(): PaymentService
{
    return app(PaymentService::class);
}

/** The newest history row of an order. The relation sorts oldest first, so the order is replaced. */
function lastHistory(Order $order): OrderStatusHistory
{
    return OrderStatusHistory::where('order_id', $order->id)->latest('id')->firstOrFail();
}

/** What SSLCommerz tells us for a good payment, as the gateway reads it. */
function paidEvent(Payment $payment, array $overrides = []): WebhookEvent
{
    return new WebhookEvent(
        eventId: hash('sha256', 'succeeded:'.$payment->reference),
        type: WebhookEvent::SUCCEEDED,
        reference: $payment->reference,
        providerReference: $overrides['providerReference'] ?? 'BANK'.$payment->id.'0001',
        amount: $overrides['amount'] ?? $payment->amount,
        currency: $overrides['currency'] ?? 'BDT',
        reason: null,
        payload: [],
        validationId: $overrides['validationId'] ?? 'VAL'.$payment->id.'0001',
        cardType: $overrides['cardType'] ?? 'VISA-Dutch Bangla',
        riskLevel: array_key_exists('riskLevel', $overrides) ? $overrides['riskLevel'] : 0,
        riskTitle: $overrides['riskTitle'] ?? 'Safe',
    );
}

function succeed(Payment $payment, array $overrides = []): string
{
    return paymentService()->applySucceeded(paidEvent($payment, $overrides));
}

/** The jobs that give back the stock and the coupon holds that ended. */
function runHoldExpiry(): void
{
    app(InventoryService::class)->releaseExpired();
    app(CouponService::class)->releaseExpired();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    seedShipping();
    fakeSslcommerzSession();
});

// ---------- Starting a payment ----------

it('starts a payment for an online order', function () {
    [$order] = onlineOrderFor($this->user, 2);

    $payment = paymentService()->initiate($order);

    expect($payment->wasRecentlyCreated)->toBeTrue();
    expect($payment->order_id)->toBe($order->id);
    expect($payment->provider)->toBe('sslcommerz');
    expect($payment->status)->toBe(PaymentRecordStatus::Initiated);
    expect($payment->amount)->toBe('2060.00');
    expect($payment->currency)->toBe('BDT');
    expect($payment->reference)->toMatch('/^P'.$order->id.'-[0-9A-Z]{10}$/');
    expect($payment->session_key)->toBe('SESSION-KEY-1');
    expect($payment->checkout_url)->toBe('https://sandbox.sslcommerz.com/EasyCheckOut/SESSION-KEY-1');
    expect($payment->provider_reference)->toBeNull();
    expect($payment->expires_at->toDateTimeString())->toBe($order->expires_at->toDateTimeString());
    expect($payment->paid_at)->toBeNull();
    expect($payment->review_reason)->toBeNull();

    Http::assertSentCount(1);
});

it('gives the same payment back while one is open and asks SSLCommerz only once', function () {
    [$order] = onlineOrderFor($this->user);

    $first = paymentService()->initiate($order);
    $second = paymentService()->initiate($order);

    expect($second->id)->toBe($first->id);
    expect($second->wasRecentlyCreated)->toBeFalse();
    expect($second->checkout_url)->toBe($first->checkout_url);
    expect(Payment::count())->toBe(1);

    Http::assertSentCount(1);
});

it('gives every attempt its own reference', function () {
    [$order] = onlineOrderFor($this->user);
    $first = paymentService()->initiate($order);
    paymentService()->applyFailed($first->reference);

    $second = paymentService()->initiate($order);

    expect($second->reference)->not->toBe($first->reference);
});

it('lets the customer try again after a failed attempt', function () {
    [$order] = onlineOrderFor($this->user);
    $first = paymentService()->initiate($order);
    paymentService()->applyFailed($first->reference, 'Card declined');

    $second = paymentService()->initiate($order);

    expect($second->id)->not->toBe($first->id);
    expect($second->status)->toBe(PaymentRecordStatus::Initiated);
    expect($first->fresh()->status)->toBe(PaymentRecordStatus::Failed);
    expect(Payment::count())->toBe(2);
});

it('allows 3 attempts and refuses the 4th', function () {
    [$order] = onlineOrderFor($this->user);

    foreach (range(1, 3) as $i) {
        paymentService()->applyFailed(paymentService()->initiate($order)->reference);
    }

    expect(Payment::count())->toBe(3);

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'Too many payment attempts for this order. Please contact us.');

    expect(Payment::count())->toBe(3);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

    Http::assertSentCount(3);
});

it('counts cancelled and expired attempts too', function () {
    [$order] = onlineOrderFor($this->user);

    paymentService()->applyFailed(paymentService()->initiate($order)->reference, null, PaymentRecordStatus::Cancelled);
    paymentService()->applyFailed(paymentService()->initiate($order)->reference, null, PaymentRecordStatus::Expired);
    paymentService()->applyFailed(paymentService()->initiate($order)->reference);

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, 'Too many payment attempts');
});

it('does not count the attempts of another order', function () {
    [$first] = onlineOrderFor($this->user);
    [$second] = onlineOrderFor(User::factory()->create());

    foreach (range(1, 3) as $i) {
        paymentService()->applyFailed(paymentService()->initiate($first)->reference);
    }

    expect(paymentService()->initiate($second)->status)->toBe(PaymentRecordStatus::Initiated);
});

it('reads the number of attempts from the config and uses 3 when the value is not valid', function () {
    config(['payments.max_attempts' => 1]);
    [$order] = onlineOrderFor($this->user);
    paymentService()->applyFailed(paymentService()->initiate($order)->reference);

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, 'Too many payment attempts');

    foreach ([0, -2, null] as $value) {
        config(['payments.max_attempts' => $value]);

        expect(PaymentService::maxAttempts())->toBe(3);
    }
});

it('refuses an order that is paid on delivery', function () {
    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000, ['stock' => 5]), 1]]));

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'This order is paid on delivery.');

    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses an order that is not waiting for payment', function (OrderStatus $status) {
    [$order] = onlineOrderFor($this->user);
    $order->update(['status' => $status]);

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'This order is not waiting for payment.');
})->with([OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled]);

it('refuses an order that is already paid', function () {
    [$order] = onlineOrderFor($this->user);
    $order->update(['payment_status' => PaymentStatus::Paid]);

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'This order is not waiting for payment.');
});

it('refuses to start a payment after the time to pay ended, even before the expiry job ran', function () {
    [$order] = onlineOrderFor($this->user);

    $this->travel(16)->minutes();

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'The time to pay for this order has ended. Please place a new order.');

    Http::assertNothingSent();
});

it('lets the customer start a payment in the last minute', function () {
    [$order] = onlineOrderFor($this->user);

    $this->travel(14)->minutes();

    expect(paymentService()->initiate($order)->status)->toBe(PaymentRecordStatus::Initiated);
});

it('does not give an open payment back after the time to pay ended', function () {
    [$order] = onlineOrderFor($this->user);
    paymentService()->initiate($order);

    $this->travel(16)->minutes();

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, 'The time to pay for this order has ended.');
});

it('refuses an order below the minimum or above the maximum of online payment', function (string $total, string $message) {
    [$order] = onlineOrderFor($this->user);
    $order->update(['grand_total' => $total]);

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, $message);

    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'below the minimum' => ['9.99', 'Online payment is available for orders of 10.00 or more.'],
    'above the maximum' => ['500000.01', 'Online payment is available for orders up to 500000.00.'],
]);

it('accepts an order exactly at the limits', function (string $total) {
    [$order] = onlineOrderFor($this->user);
    $order->update(['grand_total' => $total]);

    expect(paymentService()->initiate($order)->amount)->toBe($total);
})->with(['10.00', '500000.00']);

it('reads the limits from the config', function () {
    config(['payments.min_amount' => 100, 'payments.max_amount' => 3000]);
    [$order] = onlineOrderFor($this->user); // 2060.00

    expect(paymentService()->initiate($order)->status)->toBe(PaymentRecordStatus::Initiated);

    $order->update(['grand_total' => '99.99']);
    Payment::query()->delete();

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, '100.00 or more');
});

it('continues an attempt that was saved but never reached the provider', function () {
    [$order] = onlineOrderFor($this->user);
    $unfinished = Payment::factory()->for($order)->create([
        'amount' => $order->grand_total,
        'expires_at' => $order->expires_at,
        'session_key' => null,
        'checkout_url' => null,
    ]);

    $payment = paymentService()->initiate($order);

    expect($payment->id)->toBe($unfinished->id);
    expect($payment->wasRecentlyCreated)->toBeFalse();
    expect($payment->session_key)->toBe('SESSION-KEY-1');
    expect($payment->checkout_url)->not->toBeNull();
    expect(Payment::count())->toBe(1);
});

it('marks the attempt as failed when SSLCommerz is down, and lets the customer try again', function () {
    [$order] = onlineOrderFor($this->user);
    sslFake(fn () => throw new ConnectionException('cURL error for https://x.test?store_passwd=teststore@ssl'));

    expect(fn () => paymentService()->initiate($order))
        ->toThrow(PaymentException::class, 'Payment is not available right now. Please try again in a moment.');

    $failed = Payment::firstOrFail();

    expect($failed->status)->toBe(PaymentRecordStatus::Failed);
    expect($failed->failure_reason)->toBe('gateway_error');
    expect($failed->checkout_url)->toBeNull();
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

    fakeSslcommerzSession('SESSION-RETRY');

    $retry = paymentService()->initiate($order);

    expect($retry->id)->not->toBe($failed->id);
    expect($retry->session_key)->toBe('SESSION-RETRY');
});

it('marks the attempt as failed when SSLCommerz refuses to start the payment', function () {
    [$order] = onlineOrderFor($this->user);
    sslFake(['*/gwprocess/v4/api.php' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store credential error'])]);

    expect(fn () => paymentService()->initiate($order))->toThrow(PaymentException::class, 'Payment is not available right now.');

    expect(Payment::firstOrFail()->failure_reason)->toBe('gateway_error');
});

// ---------- Success ----------

it('confirms the order and turns the held stock into a sale', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect($variant->fresh()->reserved)->toBe(2);

    expect(succeed($payment))->toBe('confirmed');

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Confirmed);
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid);
    expect($fresh->confirmed_at)->not->toBeNull();
    expect($fresh->expires_at)->toBeNull();

    $paid = $payment->fresh();

    expect($paid->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($paid->paid_at)->not->toBeNull();
    expect($paid->review_reason)->toBeNull();
    expect($paid->failure_reason)->toBeNull();

    $stock = $variant->fresh();

    expect($stock->stock)->toBe(8);
    expect($stock->reserved)->toBe(0);

    $movement = InventoryMovement::firstOrFail();

    expect($movement->type->value)->toBe('sale');
    expect($movement->quantity)->toBe(-2);
    expect($movement->reference_type)->toBe('order');
    expect($movement->reference_id)->toBe($order->id);
    expect($movement->created_by)->toBeNull();
    expect(InventoryReservation::firstOrFail()->status)->toBe(ReservationStatus::Committed);

    $history = lastHistory($order);

    expect($history->from_status)->toBe(OrderStatus::PendingPayment);
    expect($history->to_status)->toBe(OrderStatus::Confirmed);
    expect($history->user_id)->toBeNull();
    expect($history->note)->toBe('Payment received');
});

it('saves what SSLCommerz tells us about the payment', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);

    succeed($payment, ['providerReference' => 'BANK-77', 'validationId' => 'VAL-77', 'cardType' => 'BKASH-bKash', 'riskLevel' => 0]);

    $paid = $payment->fresh();

    expect($paid->provider_reference)->toBe('BANK-77');
    expect($paid->validation_id)->toBe('VAL-77');
    expect($paid->card_type)->toBe('BKASH-bKash');
    expect($paid->risk_level)->toBe(0);
    expect($paid->session_key)->toBe('SESSION-KEY-1');
});

it('writes a note for a payment that SSLCommerz marked as risky, and still confirms it', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);

    expect(succeed($payment, ['riskLevel' => 1, 'riskTitle' => 'Risky']))->toBe('confirmed');
    expect($payment->fresh()->risk_level)->toBe(1);
    expect($payment->fresh()->review_reason)->toBe('risky_payment');
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect(lastHistory($order)->note)
        ->toBe('Payment received. The payment provider marked it as risky. Check it before shipping.');
});

it('turns the held coupon use into a counted use', function () {
    makeCoupon(['code' => 'SAVE10']);
    [$order] = onlineOrderFor($this->user, 1, 'SAVE10');
    $payment = paymentService()->initiate($order);

    expect(CouponRedemption::firstOrFail()->status)->toBe(RedemptionStatus::Reserved);

    succeed($payment);

    $redemption = CouponRedemption::firstOrFail();

    expect($redemption->status)->toBe(RedemptionStatus::Redeemed);
    expect($redemption->expires_at)->toBeNull();
});

it('sells every line of the order', function () {
    $a = buyable(1000, ['stock' => 10]);
    $b = buyable(500, ['stock' => 20]);
    $order = placeOrder($this->user, readyCart($this->user, [[$a, 2], [$b, 3]]), ['payment_method' => 'online']);

    succeed(paymentService()->initiate($order));

    expect($a->fresh()->stock)->toBe(8);
    expect($b->fresh()->stock)->toBe(17);
    expect($a->fresh()->reserved)->toBe(0);
    expect($b->fresh()->reserved)->toBe(0);
    expect(InventoryMovement::count())->toBe(2);
});

it('confirms only once when the same success is told again', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect(succeed($payment))->toBe('confirmed');
    expect(succeed($payment))->toBe('already_succeeded');

    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);
    expect($order->statusHistory()->count())->toBe(2);
});

it('refuses a payment that does not exist', function () {
    $event = new WebhookEvent('evt', WebhookEvent::SUCCEEDED, 'P1-DOESNOTEXIST', null, '10.00', 'BDT', null, []);

    expect(fn () => paymentService()->applySucceeded($event))->toThrow(PaymentException::class, 'Payment not found.');
    expect(fn () => paymentService()->applyFailed('P1-DOESNOTEXIST'))->toThrow(PaymentException::class, 'Payment not found.');
});

it('accepts the amount in any usual format', function (string $amount) {
    [$order] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect(succeed($payment, ['amount' => $amount]))->toBe('confirmed');
})->with(['2060', '2060.0', '2060.00', '2060.000']);

it('accepts the currency in small letters', function () {
    [$order] = onlineOrderFor($this->user, 2);

    expect(succeed(paymentService()->initiate($order), ['currency' => 'bdt']))->toBe('confirmed');
});

it('does not confirm the order when the amount is wrong, and flags the payment with the bank id for the refund', function (string $amount) {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect(succeed($payment, ['amount' => $amount, 'providerReference' => 'BANK-MISMATCH']))->toBe('amount_mismatch');

    $paid = $payment->fresh();

    expect($paid->status)->toBe(PaymentRecordStatus::Failed);
    expect($paid->review_reason)->toBe('amount_mismatch');
    expect($paid->failure_reason)->toBe('The amount paid did not match the order.');
    expect($paid->provider_reference)->toBe('BANK-MISMATCH');
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($variant->fresh()->reserved)->toBe(2);
    expect(InventoryMovement::count())->toBe(0);
})->with(['2059.99', '2060.01', '1.00', '9999.00']);

it('does not confirm the order when the currency is wrong', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect(succeed($payment, ['currency' => 'USD']))->toBe('amount_mismatch');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($variant->fresh()->reserved)->toBe(2);
});

it('confirms the order when an attempt that was closed turns out to have been paid', function (PaymentRecordStatus $as) {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);
    paymentService()->applyFailed($payment->reference, 'Closed', $as);

    expect(succeed($payment))->toBe('confirmed');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($variant->fresh()->stock)->toBe(8);
})->with([PaymentRecordStatus::Failed, PaymentRecordStatus::Cancelled, PaymentRecordStatus::Expired]);

it('still confirms a payment that comes in after the time ended while the hold was not released yet', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    $this->travel(16)->minutes();

    expect(succeed($payment))->toBe('confirmed');
    expect($variant->fresh()->stock)->toBe(8);
});

// ---------- Late payments and money that cannot be used ----------

it('cancels the order and flags the payment when the hold was released before the payment arrived', function () {
    makeCoupon(['code' => 'SAVE10']);
    [$order, $variant] = onlineOrderFor($this->user, 2, 'SAVE10');
    $payment = paymentService()->initiate($order);

    $this->travel(16)->minutes();
    runHoldExpiry();

    expect($variant->fresh()->reserved)->toBe(0);

    expect(succeed($payment))->toBe('late_payment');

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Cancelled);
    expect($fresh->cancel_reason)->toBe('Payment arrived after the hold expired');
    expect($fresh->cancelled_by)->toBeNull();
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid);

    $paid = $payment->fresh();

    expect($paid->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($paid->review_reason)->toBe('late_payment');
    expect($paid->paid_at)->not->toBeNull();
    expect($paid->provider_reference)->not->toBeNull();

    $stock = $variant->fresh();

    expect($stock->stock)->toBe(10);
    expect($stock->reserved)->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect(CouponRedemption::firstOrFail()->status)->not->toBe(RedemptionStatus::Redeemed);

    $history = $order->statusHistory()->get();

    expect($history)->toHaveCount(3);
    expect($history[1]->to_status)->toBe(OrderStatus::Cancelled);
    expect($history[1]->user_id)->toBeNull();
    expect($history[2]->note)->toBe('Payment received after the order was cancelled. Refund requires staff approval.');
});

it('sells nothing when only the coupon use was given back', function () {
    makeCoupon(['code' => 'SAVE10']);
    [$order, $variant] = onlineOrderFor($this->user, 2, 'SAVE10');
    $payment = paymentService()->initiate($order);

    $this->travel(16)->minutes();
    app(CouponService::class)->releaseExpired(); // the stock hold is still active

    expect(succeed($payment))->toBe('late_payment');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->fresh()->review_reason)->toBe('late_payment');

    $stock = $variant->fresh();

    expect($stock->stock)->toBe(10);
    expect($stock->reserved)->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect(InventoryReservation::firstOrFail()->status)->toBe(ReservationStatus::Released);
});

it('sells nothing when only the stock hold was given back', function () {
    makeCoupon(['code' => 'SAVE10']);
    [$order, $variant] = onlineOrderFor($this->user, 2, 'SAVE10');
    $payment = paymentService()->initiate($order);

    $this->travel(16)->minutes();
    app(InventoryService::class)->releaseExpired(); // the coupon hold is still active

    expect(succeed($payment))->toBe('late_payment');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($variant->fresh()->stock)->toBe(10);
    expect(InventoryMovement::count())->toBe(0);
    expect(CouponRedemption::firstOrFail()->status)->not->toBe(RedemptionStatus::Redeemed);
});

it('flags a payment that arrives after the customer cancelled', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    app(OrderService::class)->cancel($order, $this->user);

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Cancelled);

    expect(succeed($payment))->toBe('order_cancelled');

    $paid = $payment->fresh();

    expect($paid->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($paid->review_reason)->toBe('order_cancelled');

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Cancelled);
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid);
    expect($variant->fresh()->stock)->toBe(10);
    expect($variant->fresh()->reserved)->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect(lastHistory($order)->note)
        ->toBe('Payment received after the order was cancelled. Refund requires staff approval.');

});

it('flags a second payment for an order that is already paid', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $first = paymentService()->initiate($order);
    $second = Payment::factory()->for($order)->create(['amount' => $order->grand_total, 'expires_at' => $order->expires_at]);

    expect(succeed($first))->toBe('confirmed');
    expect(succeed($second))->toBe('duplicate_payment');

    $duplicate = $second->fresh();

    expect($duplicate->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($duplicate->review_reason)->toBe('duplicate_payment');

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Confirmed);
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid);
    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);

    expect(lastHistory($order)->note)
        ->toBe('A second payment was received for this order. Refund requires staff approval.');
    expect($first->fresh()->review_reason)->toBeNull();
});

// ---------- Failure, cancel and expiry at the gateway ----------

it('marks a payment as failed and keeps the order and its hold', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);
    $payment = paymentService()->initiate($order);

    expect(paymentService()->applyFailed($payment->reference, 'Card declined'))->toBe('failed');

    $failed = $payment->fresh();

    expect($failed->status)->toBe(PaymentRecordStatus::Failed);
    expect($failed->failure_reason)->toBe('Card declined');
    expect($failed->failed_at)->not->toBeNull();
    expect($failed->review_reason)->toBeNull();

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($variant->fresh()->reserved)->toBe(2);
    expect(InventoryReservation::firstOrFail()->status)->toBe(ReservationStatus::Active);
});

it('marks an attempt that the customer cancelled at the gateway, and lets the customer try again', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);

    expect(paymentService()->applyFailed($payment->reference, null, PaymentRecordStatus::Cancelled))->toBe('cancelled');

    $closed = $payment->fresh();

    expect($closed->status)->toBe(PaymentRecordStatus::Cancelled);
    expect($closed->failure_reason)->toBe('The payment was cancelled.');
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    expect(paymentService()->initiate($order)->id)->not->toBe($payment->id);
});

it('marks an attempt that expired at the gateway', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);

    expect(paymentService()->applyFailed($payment->reference, null, PaymentRecordStatus::Expired))->toBe('expired');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Expired);
    expect($payment->fresh()->failure_reason)->toBe('The payment session expired.');
});

it('uses a default reason and shortens a long one', function () {
    [$order] = onlineOrderFor($this->user);
    $first = paymentService()->initiate($order);

    paymentService()->applyFailed($first->reference, '  ');

    expect($first->fresh()->failure_reason)->toBe('The payment was not completed.');

    $second = paymentService()->initiate($order);

    paymentService()->applyFailed($second->reference, str_repeat('a', 300));

    expect(strlen($second->fresh()->failure_reason))->toBe(255);
});

it('records a failure only once', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);

    expect(paymentService()->applyFailed($payment->reference, 'First'))->toBe('failed');
    expect(paymentService()->applyFailed($payment->reference, 'Second'))->toBe('already_failed');

    expect($payment->fresh()->failure_reason)->toBe('First');
});

it('ignores a failure that comes after a success', function () {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);
    succeed($payment);

    expect(paymentService()->applyFailed($payment->reference, 'Too late'))->toBe('ignored_after_success');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('ignores a failure for a payment that was closed already', function (PaymentRecordStatus $status) {
    [$order] = onlineOrderFor($this->user);
    $payment = paymentService()->initiate($order);
    $payment->update(['status' => $status]);

    expect(paymentService()->applyFailed($payment->reference, 'Whatever'))->toBe('ignored');

    expect($payment->fresh()->status)->toBe($status);
})->with([PaymentRecordStatus::Cancelled, PaymentRecordStatus::Expired]);

// ---------- Cancelling an order ----------

it('closes the payment that was started when the order is cancelled', function () {
    [$order] = onlineOrderFor($this->user);
    $open = paymentService()->initiate($order);
    $failed = Payment::factory()->for($order)->failed()->create(['amount' => $order->grand_total]);

    app(OrderService::class)->cancel($order, $this->user);

    expect($open->fresh()->status)->toBe(PaymentRecordStatus::Cancelled);
    expect($failed->fresh()->status)->toBe(PaymentRecordStatus::Failed);
});

it('lets the system cancel an order without a user', function () {
    [$order, $variant] = onlineOrderFor($this->user, 2);

    app(OrderService::class)->cancel($order, null);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Cancelled);
    expect($fresh->cancel_reason)->toBe('Cancelled by system');
    expect($fresh->cancelled_by)->toBeNull();
    expect(lastHistory($order)->user_id)->toBeNull();
    expect($variant->fresh()->reserved)->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
});

it('keeps the reasons for a customer and for staff', function () {
    $staff = User::factory()->staff()->create();
    [$mine] = onlineOrderFor($this->user);
    [$theirs] = onlineOrderFor(User::factory()->create());

    app(OrderService::class)->cancel($mine, $this->user);
    app(OrderService::class)->cancel($theirs, $staff, null, true);

    expect($mine->fresh()->cancel_reason)->toBe('Cancelled by customer');
    expect($theirs->fresh()->cancel_reason)->toBe('Cancelled by staff');
});
