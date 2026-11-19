<?php

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\GatewayException;
use App\Services\Payments\InvalidWebhookException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\SslCommerzGateway;
use App\Services\Payments\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sslGateway(): SslCommerzGateway
{
    /** @var SslCommerzGateway $gateway */
    $gateway = app(PaymentGatewayManager::class)->driver('sslcommerz');

    return $gateway;
}

/** What SSLCommerz posts to us for a good payment. */
function ipnFields(array $overrides = []): array
{
    return array_merge([
        'status' => 'VALID',
        'tran_date' => '2026-11-17 10:00:00',
        'tran_id' => 'P1-ABCDEFGHIJ',
        'val_id' => 'VAL2611171000',
        'amount' => '2060.00',
        'store_amount' => '2011.40',
        'currency' => 'BDT',
        'bank_tran_id' => 'BANK2611171000',
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $overrides);
}

/** The answer of the validation API for a good payment. */
function validationBody(array $overrides = []): array
{
    return array_merge([
        'status' => 'VALID',
        'tran_date' => '2026-11-17 10:00:00',
        'tran_id' => 'P1-ABCDEFGHIJ',
        'val_id' => 'VAL2611171000',
        'amount' => '2060.00',
        'store_amount' => '2011.40',
        'bank_tran_id' => 'BANK2611171000',
        'currency' => 'BDT',
        'currency_type' => 'BDT',
        'currency_amount' => '2060.00',
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        'risk_title' => 'Safe',
    ], $overrides);
}

/** The answer of the transaction query API. */
function lookupBody(array $elements): array
{
    return ['APIConnect' => 'DONE', 'no_of_trans_found' => count($elements), 'element' => $elements];
}

function ipnRequest(array $fields = [], bool $signed = true): Request
{
    $fields = ipnFields($fields);

    return Request::create('/api/payments/sslcommerz/ipn', 'POST', $signed ? sslGateway()->sign($fields) : $fields);
}

/** The status of the exception the gateway throws, or 0 when it accepts the message. */
function rejectedWith(Closure $callback): int
{
    try {
        $callback();
    } catch (InvalidWebhookException $e) {
        return $e->status;
    }

    return 0;
}

function sslAnswers(array $validation = [], ?array $lookup = null): void
{
    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response(validationBody($validation)),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response($lookup ?? lookupBody([validationBody($validation)])),
    ]);
}

// ---------- Starting a payment ----------

it('starts a payment at SSLCommerz and gives back the session and the checkout address', function () {
    $payment = Payment::factory()->create(['reference' => 'P7-ABCDEFGHIJ', 'amount' => 2060, 'session_key' => null, 'checkout_url' => null]);
    fakeSslcommerzSession('SESSION-XYZ');

    $result = sslGateway()->initiate($payment);

    expect($result)->toBe([
        'session_key' => 'SESSION-XYZ',
        'checkout_url' => 'https://sandbox.sslcommerz.com/EasyCheckOut/SESSION-XYZ',
    ]);
});

it('sends the order, the customer and the callback addresses to SSLCommerz', function () {
    $order = Order::factory()->create([
        'number' => 'ORD-261117-000042',
        'shipping_name' => 'Rahim Uddin',
        'shipping_phone' => '01712345678',
        'shipping_address' => str_repeat('a', 80),
        'shipping_district' => 'Dhaka',
    ]);
    $payment = Payment::factory()->for($order)->create(['reference' => 'P7-ABCDEFGHIJ', 'amount' => 2060, 'session_key' => null, 'checkout_url' => null]);
    fakeSslcommerzSession();

    sslGateway()->initiate($payment);

    Http::assertSent(function (HttpRequest $request) use ($order) {
        return $request->url() === 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            && $request->method() === 'POST'
            && $request['store_id'] === 'teststore'
            && $request['store_passwd'] === 'teststore@ssl'
            && $request['total_amount'] === '2060.00'
            && $request['currency'] === 'BDT'
            && $request['tran_id'] === 'P7-ABCDEFGHIJ'
            && $request['success_url'] === 'https://shop.test/api/payments/sslcommerz/success'
            && $request['fail_url'] === 'https://shop.test/api/payments/sslcommerz/fail'
            && $request['cancel_url'] === 'https://shop.test/api/payments/sslcommerz/cancel'
            && $request['ipn_url'] === 'https://shop.test/api/payments/sslcommerz/ipn'
            && $request['cus_name'] === 'Rahim Uddin'
            && $request['cus_email'] === $order->user->email
            && $request['cus_phone'] === '01712345678'
            && $request['cus_city'] === 'Dhaka'
            && $request['cus_country'] === 'Bangladesh'
            && $request['cus_postcode'] === '1000'
            && $request['cus_add1'] === str_repeat('a', 50)
            && $request['shipping_method'] === 'NO'
            && $request['product_name'] === 'Order ORD-261117-000042'
            && $request['value_a'] === 'ORD-261117-000042';
    });
});

it('refuses when SSLCommerz does not start the payment', function (array $answer) {
    $payment = Payment::factory()->create(['session_key' => null, 'checkout_url' => null]);
    sslFake(['*/gwprocess/v4/api.php' => Http::response($answer)]);

    expect(fn () => sslGateway()->initiate($payment))->toThrow(GatewayException::class);
})->with([
    'failed' => [['status' => 'FAILED', 'failedreason' => 'Store credential error']],
    'no address' => [['status' => 'SUCCESS', 'sessionkey' => 'KEY', 'GatewayPageURL' => '']],
    'no session' => [['status' => 'SUCCESS', 'sessionkey' => '', 'GatewayPageURL' => 'https://x.test']],
    'empty answer' => [[]],
]);

it('includes the reason SSLCommerz gives', function () {
    $payment = Payment::factory()->create(['session_key' => null, 'checkout_url' => null]);
    sslFake(['*/gwprocess/v4/api.php' => Http::response(['status' => 'FAILED', 'failedreason' => 'Invalid store'])]);

    expect(fn () => sslGateway()->initiate($payment))->toThrow(GatewayException::class, 'Invalid store');
});

it('refuses when SSLCommerz answers with an error status', function () {
    $payment = Payment::factory()->create(['session_key' => null, 'checkout_url' => null]);
    sslFake(['*/gwprocess/v4/api.php' => Http::response('Server error', 500)]);

    expect(fn () => sslGateway()->initiate($payment))->toThrow(GatewayException::class, 'status 500');
});

it('does not pass on the text of a connection error, which can contain the store password', function () {
    $payment = Payment::factory()->create(['session_key' => null, 'checkout_url' => null]);
    sslFake(fn () => throw new ConnectionException('cURL error 28 for https://x.test/api.php?store_passwd=teststore@ssl'));

    try {
        sslGateway()->initiate($payment);
        $this->fail('The call should have failed.');
    } catch (GatewayException $e) {
        expect($e->getMessage())->not->toContain('teststore');
        expect($e->getMessage())->not->toContain('store_passwd');
        expect($e->getPrevious())->toBeNull();
    }
});

it('uses the sandbox address in a test store and the live address in a live store', function () {
    $payment = Payment::factory()->create(['session_key' => null, 'checkout_url' => null]);

    fakeSslcommerzSession();
    sslGateway()->initiate($payment);
    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://sandbox.sslcommerz.com/gwprocess/'));

    app()->detectEnvironment(fn () => 'production');
    config(['payments.gateways.sslcommerz.sandbox' => false]);
    fakeSslcommerzSession();
    sslGateway()->initiate($payment);
    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://securepay.sslcommerz.com/gwprocess/'));
});

// ---------- Notifications: the signature ----------

it('refuses a signed message with a wrong signature, without asking the provider', function (Closure $tamper) {
    sslFake([]);
    $fields = $tamper(sslGateway()->sign(ipnFields()));
    $request = Request::create('/api/payments/sslcommerz/ipn', 'POST', $fields);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook($request)))->toBe(401);

    Http::assertNothingSent();
})->with([
    'wrong signature' => [fn (array $fields) => ['verify_sign' => str_repeat('0', 32)] + $fields],
    'changed amount' => [fn (array $fields) => ['amount' => '1.00'] + $fields],
    'changed transaction' => [fn (array $fields) => ['tran_id' => 'P2-OTHERTRANS'] + $fields],
    'listed field removed' => [fn (array $fields) => array_diff_key($fields, ['bank_tran_id' => 1])],
]);

it('refuses a message that is not signed when a signature is required', function () {
    sslFake([]);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook(ipnRequest(signed: false))))->toBe(401);

    Http::assertNothingSent();
});

it('refuses a message signed with another store password', function () {
    sslFake([]);
    $fields = ipnFields();
    $fields['verify_key'] = implode(',', array_keys($fields));
    $data = $fields;
    unset($data['verify_key']);
    $data['store_passwd'] = md5('some-other-password');
    ksort($data);
    $fields['verify_sign'] = md5(implode('&', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($data), $data)));

    $request = Request::create('/api/payments/sslcommerz/ipn', 'POST', $fields);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook($request)))->toBe(401);
});

it('refuses a wrong signature even when the customer coming back does not need one', function () {
    sslFake([]);
    $request = Request::create('/api/payments/sslcommerz/success', 'POST', [
        'tran_id' => 'P1-ABCDEFGHIJ', 'verify_key' => 'tran_id', 'verify_sign' => 'abc',
    ]);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook($request, requireSignature: false)))->toBe(401);

    Http::assertNothingSent();
});

it('refuses a message without a transaction id', function (mixed $tranId) {
    sslFake([]);
    $request = ipnRequest(['tran_id' => $tranId]);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook($request)))->toBe(422);

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 41)],
    'a list' => [['a', 'b']],
]);

// ---------- Notifications: the provider decides ----------

it('reads a success through the validation API', function () {
    sslAnswers();

    $event = sslGateway()->parseWebhook(ipnRequest());

    expect($event->type)->toBe(WebhookEvent::SUCCEEDED);
    expect($event->reference)->toBe('P1-ABCDEFGHIJ');
    expect($event->amount)->toBe('2060.00');
    expect($event->currency)->toBe('BDT');
    expect($event->providerReference)->toBe('BANK2611171000');
    expect($event->validationId)->toBe('VAL2611171000');
    expect($event->cardType)->toBe('VISA-Dutch Bangla');
    expect($event->riskLevel)->toBe(0);
    expect($event->riskTitle)->toBe('Safe');
    expect($event->payload)->not->toHaveKey('card_no');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'sandbox.sslcommerz.com/validator/api/validationserverAPI.php')
        && str_contains($request->url(), 'val_id=VAL2611171000')
        && str_contains($request->url(), 'store_id=teststore'));
});

it('uses the answer of the provider and not the amount in the message', function () {
    sslAnswers();

    $event = sslGateway()->parseWebhook(ipnRequest(['amount' => '1.00', 'currency' => 'USD', 'status' => 'VALID']));

    expect($event->amount)->toBe('2060.00');
    expect($event->currency)->toBe('BDT');
});

it('takes the currency from currency_type, and from currency when there is none', function () {
    sslAnswers(['currency_type' => 'bdt']);
    expect(sslGateway()->parseWebhook(ipnRequest())->currency)->toBe('BDT');

    sslAnswers(['currency_type' => null, 'currency' => 'BDT']);
    expect(sslGateway()->parseWebhook(ipnRequest())->currency)->toBe('BDT');
});

it('refuses when the provider answers for another transaction', function () {
    sslAnswers(['tran_id' => 'P9-SOMEBODYELSE']);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook(ipnRequest())))->toBe(422);
});

it('refuses a success answer without an amount or a currency', function (array $answer) {
    sslAnswers($answer);

    expect(rejectedWith(fn () => sslGateway()->parseWebhook(ipnRequest())))->toBe(422);
})->with([
    'no amount' => [['amount' => null]],
    'text amount' => [['amount' => 'a lot']],
    'no currency' => [['currency' => null, 'currency_type' => null]],
]);

it('asks for the real state when the validation API does not accept the payment', function () {
    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response(['status' => 'INVALID_TRANSACTION']),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(lookupBody([validationBody(['status' => 'FAILED'])])),
    ]);

    $event = sslGateway()->parseWebhook(ipnRequest());

    expect($event->type)->toBe(WebhookEvent::FAILED);
});

it('asks for the state by transaction id when the message has no val_id', function () {
    sslAnswers();

    $event = sslGateway()->parseWebhook(ipnRequest(['val_id' => null, 'status' => 'FAILED']));

    expect($event->type)->toBe(WebhookEvent::SUCCEEDED);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'merchantTransIDvalidationAPI.php')
        && str_contains($request->url(), 'tran_id=P1-ABCDEFGHIJ'));
});

it('does not believe a message that says failed when the provider says valid', function () {
    sslAnswers();

    $event = sslGateway()->parseWebhook(ipnRequest(['status' => 'FAILED', 'val_id' => null]));

    expect($event->type)->toBe(WebhookEvent::SUCCEEDED);
});

it('does not believe a message that says valid when the provider says failed', function () {
    sslFake([
        '*/validator/api/validationserverAPI.php*' => Http::response(['status' => 'INVALID_TRANSACTION']),
        '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(lookupBody([validationBody(['status' => 'CANCELLED'])])),
    ]);

    expect(sslGateway()->parseWebhook(ipnRequest())->type)->toBe(WebhookEvent::CANCELLED);
});

it('reads the customer coming back without a signature', function () {
    sslAnswers(lookup: lookupBody([validationBody(['status' => 'CANCELLED'])]));
    $request = Request::create('/api/payments/sslcommerz/cancel', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ', 'status' => 'CANCELLED']);

    $event = sslGateway()->parseWebhook($request, requireSignature: false);

    expect($event->type)->toBe(WebhookEvent::CANCELLED);
    expect($event->reference)->toBe('P1-ABCDEFGHIJ');
});

it('maps the state of the provider', function (string $status, string $type) {
    sslAnswers(lookup: lookupBody([validationBody(['status' => $status])]));
    $request = Request::create('/api/payments/sslcommerz/fail', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']);

    expect(sslGateway()->parseWebhook($request, requireSignature: false)->type)->toBe($type);
})->with([
    ['VALID', WebhookEvent::SUCCEEDED],
    ['VALIDATED', WebhookEvent::SUCCEEDED],
    ['FAILED', WebhookEvent::FAILED],
    ['CANCELLED', WebhookEvent::CANCELLED],
    ['EXPIRED', WebhookEvent::EXPIRED],
    ['UNATTEMPTED', WebhookEvent::OTHER],
    ['PENDING', WebhookEvent::OTHER],
    ['SOMETHING_NEW', WebhookEvent::OTHER],
]);

it('reads a failure with the reason of the provider and no amount', function () {
    sslAnswers(lookup: lookupBody([['status' => 'FAILED', 'tran_id' => 'P1-ABCDEFGHIJ', 'error' => 'Card declined']]));
    $request = Request::create('/api/payments/sslcommerz/fail', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']);

    $event = sslGateway()->parseWebhook($request, requireSignature: false);

    expect($event->type)->toBe(WebhookEvent::FAILED);
    expect($event->reason)->toBe('Card declined');
    expect($event->amount)->toBe('');
    expect($event->providerReference)->toBeNull();
});

it('prefers a successful transaction when the provider knows several', function () {
    sslAnswers(lookup: lookupBody([
        validationBody(['status' => 'FAILED', 'val_id' => null]),
        validationBody(['status' => 'VALID']),
    ]));
    $request = Request::create('/api/payments/sslcommerz/fail', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']);

    expect(sslGateway()->parseWebhook($request, requireSignature: false)->type)->toBe(WebhookEvent::SUCCEEDED);
});

it('takes the last transaction when none succeeded', function () {
    sslAnswers(lookup: lookupBody([
        validationBody(['status' => 'FAILED']),
        validationBody(['status' => 'CANCELLED']),
    ]));
    $request = Request::create('/api/payments/sslcommerz/fail', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']);

    expect(sslGateway()->parseWebhook($request, requireSignature: false)->type)->toBe(WebhookEvent::CANCELLED);
});

it('reports that the provider has no record of the payment', function () {
    sslAnswers(lookup: ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]);
    $request = Request::create('/api/payments/sslcommerz/fail', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']);

    $event = sslGateway()->parseWebhook($request, requireSignature: false);

    expect($event->type)->toBe(WebhookEvent::OTHER);
    expect($event->reason)->toBe('The provider has no record of this payment.');
});

it('gives the same event id for VALID and VALIDATED, so a repeated notification is recognized', function () {
    sslAnswers(['status' => 'VALID']);
    $first = sslGateway()->parseWebhook(ipnRequest());

    sslAnswers(['status' => 'VALIDATED']);
    $second = sslGateway()->parseWebhook(ipnRequest());

    expect($second->eventId)->toBe($first->eventId);
    expect(strlen($first->eventId))->toBe(64);
});

it('gives different event ids for different payments and different outcomes', function () {
    sslAnswers(['tran_id' => 'P1-ABCDEFGHIJ']);
    $a = sslGateway()->parseWebhook(ipnRequest());

    sslAnswers(['tran_id' => 'P2-KLMNOPQRST', 'val_id' => 'VAL2']);
    $b = sslGateway()->parseWebhook(ipnRequest(['tran_id' => 'P2-KLMNOPQRST', 'val_id' => 'VAL2']));

    sslAnswers(lookup: lookupBody([validationBody(['status' => 'FAILED'])]));
    $c = sslGateway()->parseWebhook(Request::create('/x', 'POST', ['tran_id' => 'P1-ABCDEFGHIJ']), requireSignature: false);

    expect($a->eventId)->not->toBe($b->eventId);
    expect($a->eventId)->not->toBe($c->eventId);
});

it('does not pass on the text of a connection error when it asks the provider', function () {
    sslFake(fn () => throw new ConnectionException('cURL error for https://x.test/v.php?store_passwd=teststore@ssl'));

    try {
        sslGateway()->parseWebhook(ipnRequest());
        $this->fail('The call should have failed.');
    } catch (GatewayException $e) {
        expect($e->getMessage())->not->toContain('teststore');
        expect($e->getPrevious())->toBeNull();
    }
});

it('refuses when the provider answers with an error status', function () {
    sslFake(['*/validator/api/*' => Http::response('Server error', 503)]);

    expect(fn () => sslGateway()->parseWebhook(ipnRequest()))->toThrow(GatewayException::class, 'status 503');
});

// ---------- Asking for the state of a payment ----------

it('asks for the state of a payment', function () {
    $payment = Payment::factory()->create(['reference' => 'P1-ABCDEFGHIJ']);
    sslAnswers();

    $event = sslGateway()->query($payment);

    expect($event->type)->toBe(WebhookEvent::SUCCEEDED);
    expect($event->reference)->toBe('P1-ABCDEFGHIJ');
});

it('gives null when the provider has no record of the payment', function () {
    $payment = Payment::factory()->create();
    sslAnswers(lookup: ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]);

    expect(sslGateway()->query($payment))->toBeNull();
});

// ---------- Signing ----------

it('signs a message so that the gateway accepts it', function () {
    sslAnswers();
    $fields = sslGateway()->sign(ipnFields());

    expect($fields)->toHaveKeys(['verify_key', 'verify_sign']);
    expect(explode(',', $fields['verify_key']))->toContain('tran_id', 'amount', 'status');
    expect(rejectedWith(fn () => sslGateway()->parseWebhook(Request::create('/x', 'POST', $fields))))->toBe(0);
});

// ---------- The manager ----------

it('refuses a gateway without credentials', function (string $key) {
    config(["payments.gateways.sslcommerz.{$key}" => '']);

    expect(fn () => app(PaymentGatewayManager::class)->driver('sslcommerz'))
        ->toThrow(RuntimeException::class, 'The SSLCommerz store id and password are not set.');
})->with(['store_id', 'store_password']);

it('refuses a live store outside production', function () {
    config(['payments.gateways.sslcommerz.sandbox' => false]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('sslcommerz'))
        ->toThrow(RuntimeException::class, 'A live SSLCommerz store can only be used in production.');
});

it('allows a live store in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['payments.gateways.sslcommerz.sandbox' => false]);

    expect(app(PaymentGatewayManager::class)->driver('sslcommerz'))->toBeInstanceOf(SslCommerzGateway::class);
});

it('refuses a gateway that is not configured, also the old mock gateway', function (string $name) {
    expect(fn () => app(PaymentGatewayManager::class)->driver($name))
        ->toThrow(InvalidArgumentException::class, "The payment gateway [{$name}] is not configured.");
})->with(['paypal', 'mock']);

it('knows which gateways exist and uses the default one', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->has('sslcommerz'))->toBeTrue();
    expect($manager->has('mock'))->toBeFalse();
    expect($manager->driver()->name())->toBe('sslcommerz');
});
