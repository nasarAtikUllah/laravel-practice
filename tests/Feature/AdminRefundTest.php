<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Jobs\ProcessRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows only admins to list and inspect refunds', function () {
    $payment = Payment::factory()->succeeded()->create();
    $refund = Refund::factory()->for($payment)->create(['order_id' => $payment->order_id]);

    $this->getJson('/api/admin/refunds')->assertUnauthorized();
    $this->getJson("/api/admin/refunds/{$refund->id}")->assertUnauthorized();

    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson('/api/admin/refunds')->assertForbidden();
    $this->getJson("/api/admin/refunds/{$refund->id}")->assertForbidden();
    $this->postJson("/api/admin/refunds/{$refund->id}/resolve", [
        'status' => 'succeeded',
        'resolution_note' => 'Verified with the provider.',
    ])->assertForbidden();

    actingAsAdmin();

    $this->getJson('/api/admin/refunds')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $refund->reference)
        ->assertJsonPath('data.0.payment_reference', $payment->reference)
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/api/admin/refunds/{$refund->id}")
        ->assertOk()
        ->assertJsonPath('data.reference', $refund->reference)
        ->assertJsonPath('data.order_number', $payment->order->number);
});

it('filters the admin refund list by status and searches related references', function () {
    $failedPayment = Payment::factory()->succeeded()->create();
    $failed = Refund::factory()->for($failedPayment)->create([
        'order_id' => $failedPayment->order_id,
        'status' => RefundStatus::Failed,
    ]);
    $pendingPayment = Payment::factory()->succeeded()->create();
    Refund::factory()->for($pendingPayment)->create([
        'order_id' => $pendingPayment->order_id,
        'status' => RefundStatus::Pending,
    ]);
    actingAsAdmin();

    $this->getJson('/api/admin/refunds?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $failed->reference);

    $this->getJson("/api/admin/refunds?search={$failedPayment->reference}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $failed->reference);
});

it('lets an admin resolve a processing refund and records the resolution', function () {
    Queue::fake([ProcessRefund::class]);
    $admin = actingAsAdmin();
    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::RefundPending,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['paid_amount' => 1060]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'amount' => 1000,
        'status' => RefundStatus::Processing,
        'provider_refund_id' => 'SSL-REFUND-4',
    ]);

    $this->postJson("/api/admin/refunds/{$refund->id}/resolve", [
        'status' => 'succeeded',
        'provider_refund_id' => 'SSL-REFUND-4',
        'resolution_note' => 'Verified as successful in SSLCommerz.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.resolved_by', $admin->id)
        ->assertJsonPath('data.resolution_note', 'Verified as successful in SSLCommerz.');

    expect($refund->fresh()->resolved_at)->not->toBeNull();
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);

    Queue::assertNothingPushed();
});

it('does not resolve a refund that is not processing', function () {
    actingAsAdmin();
    $payment = Payment::factory()->succeeded()->create();
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Pending,
    ]);

    $this->postJson("/api/admin/refunds/{$refund->id}/resolve", [
        'status' => 'succeeded',
        'resolution_note' => 'Verified with the provider.',
    ])->assertStatus(409);

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
    expect($refund->fresh()->resolved_at)->toBeNull();
});

it('requires a failure reason when an admin resolves a refund as failed', function () {
    actingAsAdmin();
    $payment = Payment::factory()->succeeded()->create();
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Processing,
    ]);

    $this->postJson("/api/admin/refunds/{$refund->id}/resolve", [
        'status' => 'failed',
        'resolution_note' => 'Provider confirmed a failed refund.',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('failure_reason');

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
});
