<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs a provider-confirmed partial refund and updates a cancelled order', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::RefundPending,
    ]);
    $payment = Payment::factory()->for($order)->succeeded()->create([
        'paid_amount' => 1060,
        'provider_reference' => 'BANK-1',
    ]);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $order->id,
        'amount' => 1000,
        'status' => RefundStatus::Processing,
        'provider_refund_id' => 'SSL-REFUND-1',
        'updated_at' => now()->subMinutes(6),
    ]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'status' => 'success',
            'refund_ref_id' => 'SSL-REFUND-1',
        ]),
    ]);

    $this->artisan('refunds:sync')
        ->expectsOutput('Checked 1 refund(s): 1 succeeded, 0 failed, 0 processing, 0 unchanged, 0 could not be checked.')
        ->assertSuccessful();

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request['refund_ref_id'] === 'SSL-REFUND-1');
});

it('keeps processing refunds in processing and rate limits repeated status queries', function () {
    $payment = Payment::factory()->succeeded()->create(['provider_reference' => 'BANK-2']);
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Processing,
        'provider_refund_id' => 'SSL-REFUND-2',
        'updated_at' => now()->subMinutes(6),
    ]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'status' => 'processing',
            'refund_ref_id' => 'SSL-REFUND-2',
        ]),
    ]);

    $this->artisan('refunds:sync')
        ->expectsOutput('Checked 1 refund(s): 0 succeeded, 0 failed, 1 processing, 0 unchanged, 0 could not be checked.')
        ->assertSuccessful();

    $this->artisan('refunds:sync')->assertSuccessful();

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
    Http::assertSentCount(1);
});

it('leaves the refund processing when its status cannot be checked', function () {
    $payment = Payment::factory()->succeeded()->create();
    $refund = Refund::factory()->for($payment)->create([
        'order_id' => $payment->order_id,
        'status' => RefundStatus::Processing,
        'provider_refund_id' => 'SSL-REFUND-3',
        'updated_at' => now()->subMinutes(6),
    ]);

    sslFake(fn () => throw new ConnectionException('Provider unavailable.'));

    $this->artisan('refunds:sync')
        ->expectsOutput("Refund {$refund->reference} could not be checked.")
        ->expectsOutput('Checked 1 refund(s): 0 succeeded, 0 failed, 0 processing, 0 unchanged, 1 could not be checked.')
        ->assertFailed();

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing);
});
