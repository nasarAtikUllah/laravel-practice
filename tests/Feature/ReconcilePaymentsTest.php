<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports a refund with an unknown provider outcome without retrying it', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::RefundPending,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'amount' => 900,
        'status' => RefundStatus::Processing,
        'updated_at' => now()->subMinutes(6),
    ]);

    $this->artisan('payments:reconcile')
        ->expectsOutput('1 refund(s) need attention.')
        ->expectsOutputToContain('provider outcome is unknown; inspect before retrying')
        ->assertFailed();

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
    expect($refund->fresh()->attempts)->toBe(0);
});

it('reports a clean paid order and refund ledger as reconciled', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_status' => PaymentStatus::Paid,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['paid_amount' => 1000]);
    Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'amount' => 250,
        'status' => RefundStatus::Succeeded,
    ]);

    $this->artisan('payments:reconcile')
        ->expectsOutput('No problems found.')
        ->assertSuccessful();
});

it('reports pending refunds that may be stuck in the queue', function () {
    $payment = Payment::factory()->succeeded()->create(['paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Pending,
        'updated_at' => now()->subMinutes(6),
    ]);

    $this->artisan('payments:reconcile')
        ->expectsOutput('1 refund(s) need attention.')
        ->expectsOutputToContain('refund is still queued; check that a queue worker is running')
        ->assertFailed();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
});

it('treats a cancelled order with an intentionally retained delivery fee as partially refunded', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::PartiallyRefunded,
        'delivery_fee' => 60,
        'grand_total' => 1060,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['paid_amount' => 1060]);
    Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'amount' => 1000,
        'status' => RefundStatus::Succeeded,
    ]);

    $this->artisan('payments:reconcile')
        ->expectsOutput('No problems found.')
        ->assertSuccessful();
});

it('reports flagged captured money that has no refund reserved', function (string $reviewReason) {
    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_status' => PaymentStatus::Paid,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create([
        'paid_amount' => 1000,
        'review_reason' => $reviewReason,
    ]);
    expect($payment->fresh()->review_reason)->toBe($reviewReason);

    $this->artisan('payments:reconcile')
        ->expectsOutput('1 payment(s) need staff review or a refund.')
        ->expectsOutput("{$payment->reference}: {$reviewReason}: 1000.00 captured funds need staff review or a refund")
        ->assertFailed();
})->with([
    'duplicate payment' => 'duplicate_payment',
    'late payment' => 'late_payment',
]);

it('reports definitive refund failures for admin action', function () {
    $payment = Payment::factory()->succeeded()->create(['paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Failed,
        'failure_reason' => 'Provider rejected this refund.',
    ]);

    $this->artisan('payments:reconcile')
        ->expectsOutput('1 refund(s) need attention.')
        ->expectsOutput("{$refund->reference}: refund failed: Provider rejected this refund.")
        ->assertFailed();
});
