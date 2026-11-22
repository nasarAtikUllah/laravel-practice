<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on the staff payment routes', function () {
    $payment = Payment::factory()->create();

    $this->getJson('/api/admin/payments')->assertUnauthorized();
    $this->getJson("/api/admin/payments/{$payment->reference}")->assertUnauthorized();
});

it('gives customers 403 on the staff payment routes, also for references that do not exist', function () {
    Sanctum::actingAs(User::factory()->create());
    $payment = Payment::factory()->create();

    $this->getJson('/api/admin/payments')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.');
    $this->getJson("/api/admin/payments/{$payment->reference}")->assertForbidden();
    $this->getJson('/api/admin/payments/P999-ABCDEFGHIJ')->assertForbidden();
});

it('lets staff and admins reach the staff payment routes', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());
    $payment = Payment::factory()->create();

    $this->getJson('/api/admin/payments')->assertOk();
    $this->getJson("/api/admin/payments/{$payment->reference}")->assertOk();
})->with(['staff', 'admin']);

describe('when staff', function () {
    beforeEach(function () {
        $this->staff = User::factory()->staff()->create();
        Sanctum::actingAs($this->staff);
    });

    // ---------- Read only ----------

    it('has no way to change a payment', function () {
        $payment = Payment::factory()->create();

        $this->postJson('/api/admin/payments')->assertStatus(405);
        $this->putJson("/api/admin/payments/{$payment->reference}", ['status' => 'succeeded'])->assertStatus(405);
        $this->deleteJson("/api/admin/payments/{$payment->reference}")->assertStatus(405);

        expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Initiated);
    });

    // ---------- List ----------

    it('lists payments newest first with the order and the customer', function () {
        $customer = User::factory()->create(['name' => 'Rahim Uddin', 'email' => 'rahim@example.com']);
        $order = Order::factory()->for($customer)->create([
            'number' => 'ORD-261118-000010',
            'status' => OrderStatus::PendingPayment,
            'payment_method' => PaymentMethod::Online,
        ]);
        $first = Payment::factory()->for($order)->create(['reference' => 'P10-AAAAAAAAAA']);
        $second = Payment::factory()->create(['reference' => 'P11-BBBBBBBBBB']);

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('message', 'Payments retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.reference', $second->reference)
            ->assertJsonPath('data.1.reference', 'P10-AAAAAAAAAA')
            ->assertJsonPath('data.1.order.number', 'ORD-261118-000010')
            ->assertJsonPath('data.1.order.status', 'pending_payment')
            ->assertJsonPath('data.1.customer.name', 'Rahim Uddin')
            ->assertJsonPath('data.1.customer.email', 'rahim@example.com')
            ->assertJsonPath('data.1.needs_review', false)
            ->assertJsonMissingPath('data.1.session_key')
            ->assertJsonMissingPath('data.1.events');
    });

    it('shows what the provider told us and why money needs a person', function () {
        Payment::factory()->succeeded()->create([
            'provider_reference' => 'BANK-1',
            'validation_id' => 'VAL-1',
            'card_type' => 'VISA-Dutch Bangla',
            'risk_level' => 1,
            'review_reason' => 'late_payment',
        ]);

        $this->getJson('/api/admin/payments')
            ->assertJsonPath('data.0.provider_reference', 'BANK-1')
            ->assertJsonPath('data.0.validation_id', 'VAL-1')
            ->assertJsonPath('data.0.card_type', 'VISA-Dutch Bangla')
            ->assertJsonPath('data.0.risk_level', 1)
            ->assertJsonPath('data.0.review_reason', 'late_payment')
            ->assertJsonPath('data.0.needs_review', true);
    });

    it('shows an empty list when there are no payments', function () {
        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    });

    it('paginates 15 payments per page and supports per_page', function () {
        Payment::factory()->count(20)->create();

        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 20);
        $this->getJson('/api/admin/payments?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 5);
    });

    it('filters by status, provider and whether it needs a person', function () {
        Payment::factory()->succeeded()->create(['review_reason' => 'late_payment']);
        Payment::factory()->succeeded()->create();
        Payment::factory()->failed()->create();
        Payment::factory()->create(['provider' => 'other-provider']);

        $this->getJson('/api/admin/payments?status=failed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/admin/payments?status=succeeded')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/payments?provider=other-provider')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/payments?needs_review=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.needs_review', true);
        $this->getJson('/api/admin/payments?needs_review=0')->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/payments?status=succeeded&needs_review=1')->assertJsonCount(1, 'data');
    });

    it('searches by the start of the reference, the bank id and the order number', function () {
        Payment::factory()->create(['reference' => 'P10-AAAAAAAAAA', 'provider_reference' => 'BANK2611180001']);
        Payment::factory()->create(['reference' => 'P20-BBBBBBBBBB', 'provider_reference' => 'BANK2611180002']);
        $order = Order::factory()->create(['number' => 'ORD-261118-000777']);
        Payment::factory()->for($order)->create(['reference' => 'P30-CCCCCCCCCC']);

        $this->getJson('/api/admin/payments?search=P10')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'P10-AAAAAAAAAA');
        $this->getJson('/api/admin/payments?search=BANK2611180002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'P20-BBBBBBBBBB');
        $this->getJson('/api/admin/payments?search=ORD-261118-000777')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'P30-CCCCCCCCCC');
    });

    it('searches by the name or the email of the customer', function () {
        $karim = User::factory()->create(['name' => 'Karim Hossain', 'email' => 'karim@example.com']);
        Payment::factory()->for(Order::factory()->for($karim))->create();
        Payment::factory()->create();

        $this->getJson('/api/admin/payments?search=karim hossain')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/payments?search=karim@example')->assertJsonCount(1, 'data');
    });

    it('matches a percent sign in the search literally', function () {
        Payment::factory()->create();

        $this->getJson('/api/admin/payments?search=%25')->assertOk()->assertJsonCount(0, 'data');
    });

    it('filters by the date the payment was started, including the whole last day', function () {
        Payment::factory()->create(['created_at' => '2026-11-09 23:59:59']);
        Payment::factory()->create(['created_at' => '2026-11-10 00:00:00']);
        Payment::factory()->create(['created_at' => '2026-11-12 23:59:59']);
        Payment::factory()->create(['created_at' => '2026-11-13 00:00:00']);

        $this->getJson('/api/admin/payments?from=2026-11-10&to=2026-11-12')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/payments?from=2026-11-12')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/payments?to=2026-11-09')->assertJsonCount(1, 'data');
    });

    it('rejects bad list parameters', function (string $query, string $field) {
        $this->getJson("/api/admin/payments?{$query}")->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        ['status=banana', 'status'],
        ['needs_review=maybe', 'needs_review'],
        ['from=not-a-date', 'from'],
        ['from=2026-11-12&to=2026-11-10', 'to'],
        ['page=0', 'page'],
        ['per_page=101', 'per_page'],
    ]);

    it('uses the same number of queries however many payments there are', function () {
        Payment::factory()->count(2)->create();

        DB::enableQueryLog();
        $this->getJson('/api/admin/payments')->assertOk();
        $small = count(DB::getQueryLog());

        Payment::factory()->count(8)->create();

        DB::flushQueryLog();
        $this->getJson('/api/admin/payments')->assertOk();
        $large = count(DB::getQueryLog());

        expect($large)->toBe($small);
    });

    // ---------- Show ----------

    it('shows a payment with its order, customer and events, without the raw message', function () {
        $payment = Payment::factory()->succeeded()->create(['reference' => 'P50-DDDDDDDDDD']);
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'sslcommerz', 'event_id' => 'e1', 'type' => 'other', 'payload' => ['secret' => 'x'], 'result' => 'ignored']);
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'sslcommerz', 'event_id' => 'e2', 'type' => 'succeeded', 'payload' => ['secret' => 'y'], 'result' => 'confirmed']);

        $this->getJson('/api/admin/payments/P50-DDDDDDDDDD')
            ->assertOk()
            ->assertJsonPath('message', 'Payment retrieved successfully.')
            ->assertJsonPath('data.reference', 'P50-DDDDDDDDDD')
            ->assertJsonPath('data.order.number', $payment->order->number)
            ->assertJsonPath('data.customer.email', $payment->order->user->email)
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.type', 'other')
            ->assertJsonPath('data.events.0.result', 'ignored')
            ->assertJsonPath('data.events.1.type', 'succeeded')
            ->assertJsonPath('data.events.1.result', 'confirmed')
            ->assertJsonMissingPath('data.events.0.payload')
            ->assertJsonMissingPath('data.events.0.event_id');
    });

    it('returns 404 for a payment that does not exist or a bad reference', function (string $reference) {
        $this->getJson("/api/admin/payments/{$reference}")->assertNotFound();
    })->with(['P999-ABCDEFGHIJ', 'not-a-reference', 'P1-abc']);

    // ---------- The staff order view ----------

    it('shows the payments of an order in the staff order view', function () {
        $order = Order::factory()->create(['status' => OrderStatus::PendingPayment]);
        Payment::factory()->for($order)->failed()->create(['reference' => 'P60-EEEEEEEEEE']);
        Payment::factory()->for($order)->succeeded()->create(['reference' => 'P61-FFFFFFFFFF', 'review_reason' => 'late_payment']);

        $this->getJson("/api/admin/orders/{$order->number}")
            ->assertOk()
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.reference', 'P60-EEEEEEEEEE')
            ->assertJsonPath('data.payments.0.status', 'failed')
            ->assertJsonPath('data.payments.1.review_reason', 'late_payment')
            ->assertJsonMissingPath('data.payments.0.session_key');
    });

    it('does not show payments in the customer order view', function () {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create();
        Payment::factory()->for($order)->create();

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/{$order->number}")->assertOk()->assertJsonMissingPath('data.payments');
    });
});
