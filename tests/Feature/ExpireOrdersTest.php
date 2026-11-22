<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('cancels expired COD orders and releases their state', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::PendingConfirmation,
        'payment_method' => PaymentMethod::Cod,
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('orders:expire')
        ->expectsOutput('Checked 1 expired order(s), cancelled 1, 0 could not be processed.')
        ->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->cancel_reason)->toBe('Order expired before payment or confirmation');
});

it('checks an online payment with the provider before cancelling an expired order', function () {
    $user = User::factory()->create();
    seedShipping();
    [$order, $variant] = onlineOrderFor($user);
    fakeSslcommerzSession();
    $payment = app(PaymentService::class)->initiate($order);
    $order->update(['expires_at' => now()->subMinute()]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'no_of_trans_found' => 0,
            'element' => [],
        ]),
    ]);

    $this->artisan('orders:expire')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Cancelled);
    expect($variant->fresh()->reserved)->toBe(0);
});

it('keeps an expired order when the provider confirms its payment', function () {
    $user = User::factory()->create();
    seedShipping();
    [$order, $variant] = onlineOrderFor($user);
    fakeSslcommerzSession();
    $payment = app(PaymentService::class)->initiate($order);
    $order->update(['expires_at' => now()->subMinute()]);

    sslFake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'no_of_trans_found' => 1,
            'element' => [[
                'status' => 'VALID',
                'tran_id' => $payment->reference,
                'val_id' => 'VALIDATION-1',
                'amount' => $payment->amount,
                'currency_type' => 'BDT',
                'bank_tran_id' => 'BANK-EXPIRED-ORDER',
            ]],
        ]),
    ]);

    $this->artisan('orders:expire')
        ->expectsOutput('Checked 1 expired order(s), cancelled 0, 0 could not be processed.')
        ->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Succeeded);
    expect($variant->fresh()->stock)->toBe(8);
});

it('does not cancel an expired online order when the provider cannot be checked', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::PendingPayment,
        'payment_method' => PaymentMethod::Online,
        'expires_at' => now()->subMinute(),
    ]);
    Payment::factory()->for($order)->create(['created_at' => now()->subMinutes(3)]);

    sslFake(fn () => throw new ConnectionException('Provider unavailable.'));

    $this->artisan('orders:expire')
        ->expectsOutput("Order {$order->number} was not expired because its payment could not be checked.")
        ->assertFailed();

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});
