<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Jobs\ProcessRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows only an admin to request or retry a refund', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'status' => RefundStatus::Failed,
    ]);

    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson("/api/admin/payments/{$payment->reference}/refunds", [
        'amount' => 100,
        'reason' => 'Product return',
        'idempotency_key' => 'staff-refund-1',
    ])->assertForbidden();

    $this->postJson("/api/admin/refunds/{$refund->id}/retry")->assertForbidden();

    expect(Refund::count())->toBe(1);
});

it('records an admin refund request and prevents the payment from being over-refunded', function () {
    Queue::fake([ProcessRefund::class]);
    actingAsAdmin();
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);

    $this->postJson("/api/admin/payments/{$payment->reference}/refunds", [
        'amount' => 300,
        'reason' => 'Product return',
        'idempotency_key' => 'refund-300',
    ])->assertCreated()
        ->assertJsonPath('data.amount', '300.00')
        ->assertJsonPath('data.status', 'pending');

    expect(Refund::where('payment_id', $payment->id)->firstOrFail()->status)->toBe(RefundStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    $this->postJson("/api/admin/payments/{$payment->reference}/refunds", [
        'amount' => 700.01,
        'reason' => 'Additional refund',
        'idempotency_key' => 'refund-too-much',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'The refund amount exceeds the money received and not already refunded.');

    expect(Refund::count())->toBe(1);
});

it('returns the original refund for an idempotent request', function () {
    Queue::fake([ProcessRefund::class]);
    actingAsAdmin();
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);
    $payload = ['amount' => 250, 'reason' => 'Product return', 'idempotency_key' => 'same-key'];

    $first = $this->postJson("/api/admin/payments/{$payment->reference}/refunds", $payload)->assertCreated();
    $second = $this->postJson("/api/admin/payments/{$payment->reference}/refunds", $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Refund::count())->toBe(1);
});

it('rejects reusing a refund idempotency key with a different amount', function () {
    Queue::fake([ProcessRefund::class]);
    actingAsAdmin();
    $payment = Payment::factory()->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);
    $payload = ['amount' => 250, 'reason' => 'Product return', 'idempotency_key' => 'same-key'];

    $this->postJson("/api/admin/payments/{$payment->reference}/refunds", $payload)->assertCreated();
    $this->postJson("/api/admin/payments/{$payment->reference}/refunds", [
        ...$payload,
        'amount' => 300,
    ])->assertStatus(409);

    expect(Refund::count())->toBe(1);
});

it('sends an approved refund to SSLCommerz and keeps duplicate refunds off the order payment status', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    $payment = Payment::factory()->for($order)->succeeded()->create([
        'amount' => 1000,
        'paid_amount' => 1000,
        'provider_reference' => 'BANK-REFUND-1',
    ]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'reference' => 'R'.str_repeat('A', 29),
        'amount' => 250,
        'reason' => 'Duplicate payment',
    ]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'status' => 'success',
            'refund_ref_id' => 'PROVIDER-REFUND-1',
        ]),
    ]);

    app(RefundService::class)->process($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
    expect($refund->fresh()->provider_refund_id)->toBe('PROVIDER-REFUND-1');
    expect($refund->fresh()->attempts)->toBe(1);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    Http::assertSent(fn ($request) => $request['bank_tran_id'] === 'BANK-REFUND-1'
        && $request['refund_amount'] === '250.00'
        && $request['refe_id'] === $refund->reference
        && $request['refund_trans_id'] === $refund->reference);
});

it('stores the provider refund reference when SSLCommerz reports processing', function () {
    $payment = Payment::factory()->succeeded()->create([
        'paid_amount' => 1000,
        'provider_reference' => 'BANK-REFUND-PROCESSING',
    ]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'reference' => 'R'.str_repeat('D', 29),
    ]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'status' => 'processing',
            'refund_ref_id' => 'SSL-REFUND-PROCESSING',
        ]),
    ]);

    app(RefundService::class)->process($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
    expect($refund->fresh()->provider_refund_id)->toBe('SSL-REFUND-PROCESSING');
    expect($refund->fresh()->attempts)->toBe(1);
});

it('leaves a timed-out refund processing and never automatically sends it again', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    $payment = Payment::factory()->for($order)->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'reference' => 'R'.str_repeat('B', 29),
    ]);

    sslFake(fn () => throw new ConnectionException('Provider timed out.'));

    app(RefundService::class)->process($refund->id);
    app(RefundService::class)->process($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
    expect($refund->fresh()->attempts)->toBe(1);
});

it('marks a refund failed when its provider transaction reference is missing', function () {
    $payment = Payment::factory()->succeeded()->create([
        'amount' => 1000,
        'paid_amount' => 1000,
        'provider_reference' => null,
    ]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'reference' => 'R'.str_repeat('C', 29),
    ]);

    app(RefundService::class)->process($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatus::Failed);
    expect($refund->fresh()->failure_reason)->toBe('The provider transaction reference is missing.');
    expect($refund->fresh()->attempts)->toBe(0);
});

it('lets staff cancel a paid order with an explicit delivery refund choice', function (bool $includeDeliveryFee, string $expectedAmount) {
    Queue::fake([ProcessRefund::class]);
    $staff = User::factory()->staff()->create();
    Sanctum::actingAs($staff);
    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_status' => PaymentStatus::Paid,
        'items_total' => 1000,
        'delivery_fee' => 60,
        'grand_total' => 1060,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create([
        'amount' => 1060,
        'paid_amount' => 1060,
        'provider_reference' => 'BANK-CANCEL-1',
    ]);

    $this->postJson("/api/admin/orders/{$order->number}/cancel", [
        'reason' => 'Customer requested cancellation',
        'include_delivery_fee' => $includeDeliveryFee,
    ])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.payment_status', 'refund_pending');

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::RefundPending);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->refunds()->firstOrFail()->amount)->toBe($expectedAmount);
    expect($payment->refunds()->firstOrFail()->requested_by)->toBe($staff->id);
})->with([
    'delivery charge retained' => [false, '1000.00'],
    'delivery charge refunded' => [true, '1060.00'],
]);

it('marks a cancelled order partially refunded or refunded after the provider confirms the return', function (
    bool $includeDeliveryFee,
    string $expectedAmount,
    PaymentStatus $expectedPaymentStatus,
) {
    Queue::fake([ProcessRefund::class]);
    $staff = User::factory()->staff()->create();
    Sanctum::actingAs($staff);
    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_status' => PaymentStatus::Paid,
        'items_total' => 1000,
        'delivery_fee' => 60,
        'grand_total' => 1060,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create([
        'amount' => 1060,
        'paid_amount' => 1060,
        'provider_reference' => 'BANK-CANCEL-2',
    ]);

    $this->postJson("/api/admin/orders/{$order->number}/cancel", [
        'reason' => 'Customer requested cancellation',
        'include_delivery_fee' => $includeDeliveryFee,
    ])->assertOk();

    $refund = $payment->refunds()->firstOrFail();
    expect($refund->amount)->toBe($expectedAmount);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'status' => 'success',
            'refund_ref_id' => 'SSL-CANCEL-REFUND',
        ]),
    ]);

    app(RefundService::class)->process($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->payment_status)->toBe($expectedPaymentStatus);
})->with([
    'delivery charge retained' => [false, '1000.00', PaymentStatus::PartiallyRefunded],
    'delivery charge returned' => [true, '1060.00', PaymentStatus::Refunded],
]);

it('requires a delivery charge choice before staff cancels a paid order', function () {
    actingAsAdmin();
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);
    Payment::factory()->for($order)->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);

    $this->postJson("/api/admin/orders/{$order->number}/cancel", ['reason' => 'Customer requested cancellation'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('include_delivery_fee');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect(Refund::count())->toBe(0);
});

it('allows an admin to explicitly retry a failed refund', function () {
    Queue::fake([ProcessRefund::class]);
    actingAsAdmin();
    $payment = Payment::factory()->succeeded()->create(['paid_amount' => 1000]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Failed,
        'failure_reason' => 'Provider rejected the request.',
    ]);

    $this->postJson("/api/admin/refunds/{$refund->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
});

it('prevents retrying a failed refund after the remaining amount has been reserved', function () {
    actingAsAdmin();
    $payment = Payment::factory()->succeeded()->create(['amount' => 1000, 'paid_amount' => 1000]);
    $failed = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'amount' => 800,
        'status' => RefundStatus::Failed,
    ]);
    Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'amount' => 500,
        'status' => RefundStatus::Pending,
    ]);

    $this->postJson("/api/admin/refunds/{$failed->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('message', 'The refund amount exceeds the money received and not already refunded.');

    expect($failed->fresh()->status)->toBe(RefundStatus::Failed);
});
