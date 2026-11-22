<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SslCommerzGateway implements PaymentGateway
{
    private const SUCCESS = ['VALID', 'VALIDATED'];

    public function __construct(
        private readonly string $storeId,
        private readonly string $storePassword,
        private readonly bool $sandbox,
        private readonly string $callbackBase,
        private readonly int $timeout,
        private readonly string $postcode,
        private readonly bool $verifySignature = true,
    ) {}

    public function name(): string
    {
        return 'sslcommerz';
    }

    // ---------- Starting a payment ----------

    public function initiate(Payment $payment): array
    {
        $payment->loadMissing('order.user');
        $order = $payment->order;

        $response = $this->send(fn () => Http::asForm()->timeout($this->timeout)->post($this->host().'/gwprocess/v4/api.php', [
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'total_amount' => Money::format(Money::cents($payment->amount)),
            'currency' => $payment->currency,
            'tran_id' => $payment->reference,
            'success_url' => $this->callback('success'),
            'fail_url' => $this->callback('fail'),
            'cancel_url' => $this->callback('cancel'),
            'ipn_url' => $this->callback('ipn'),
            'cus_name' => Str::limit($order->shipping_name, 50, ''),
            'cus_email' => Str::limit((string) $order->user->email, 50, ''),
            'cus_add1' => Str::limit($order->shipping_address, 50, ''),
            'cus_city' => Str::limit($order->shipping_district, 50, ''),
            'cus_postcode' => $this->postcode,
            'cus_country' => 'Bangladesh',
            'cus_phone' => $order->shipping_phone,
            'shipping_method' => 'NO',
            'product_name' => 'Order '.$order->number,
            'product_category' => 'General',
            'product_profile' => 'general',
            'value_a' => $order->number,
        ]));

        $data = $response->json();

        if (
            ! is_array($data)
            || ($data['status'] ?? null) !== 'SUCCESS'
            || empty($data['GatewayPageURL'])
            || empty($data['sessionkey'])
        ) {
            $why = is_array($data) ? trim((string) ($data['failedreason'] ?? '')) : '';

            throw new GatewayException('SSLCommerz did not start the payment'.($why !== '' ? ': '.Str::limit($why, 200) : '.'));
        }

        return [
            'session_key' => (string) $data['sessionkey'],
            'checkout_url' => (string) $data['GatewayPageURL'],
        ];
    }

    // ---------- Notifications ----------

    public function parseWebhook(Request $request, bool $requireSignature = true): WebhookEvent
    {
        $reference = $this->text($request->input('tran_id'), 40);

        if ($reference === null) {
            throw InvalidWebhookException::malformed('tran_id is missing or not valid.');
        }

        // A message that carries a signature must carry a correct one.
        // The server-to-server call (IPN) must always be signed. The customer coming back may not be.
        if ($this->verifySignature
            && ($requireSignature || $request->filled('verify_sign'))
            && ! $this->hasValidSignature($request)) {
            throw InvalidWebhookException::signature();
        }
        // The message only tells us which payment to look at. The answer comes from the provider.
        $validationId = $this->text($request->input('val_id'), 100);

        $data = $validationId !== null ? $this->validate($validationId, $reference) : null;
        $data ??= $this->lookup($reference);

        return $this->event($reference, $data);
    }

    public function query(Payment $payment): ?WebhookEvent
    {
        $data = $this->lookup($payment->reference);

        return $data === null ? null : $this->event($payment->reference, $data);
    }

    public function refund(Payment $payment, Refund $refund): array
    {
        if (trim((string) $payment->provider_reference) === '') {
            throw new GatewayException('The payment has no provider transaction reference.');
        }

        $response = $this->send(fn () => Http::timeout($this->timeout)->get($this->host().'/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $payment->provider_reference,
            'refund_trans_id' => $refund->reference,
            'refund_amount' => Money::format(Money::cents($refund->amount)),
            'refund_remarks' => $refund->reason,
            'refe_id' => $refund->reference,
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'format' => 'json',
        ]));

        $data = $response->json();

        if (! is_array($data) || ($data['APIConnect'] ?? null) !== 'DONE') {
            throw new GatewayException('SSLCommerz could not confirm the refund request.');
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $providerRefundId = $this->text($data['refund_ref_id'] ?? null, 100)
            ?? $this->text($data['refund_trans_id'] ?? null, 100);

        return [
            'status' => match ($status) {
                'success' => 'succeeded',
                'failed', 'failure' => 'failed',
                default => 'processing',
            },
            'provider_refund_id' => $providerRefundId,
            'failure_reason' => in_array($status, ['failed', 'failure'], true)
                ? 'SSLCommerz rejected the refund request.'
                : null,
        ];
    }

    /**
     * Adds the signature fields the way SSLCommerz does. Used by the tests.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function sign(array $fields): array
    {
        $signedFields = [];

        foreach ($fields as $key => $value) {
            if (is_scalar($value)) {
                $signedFields[$key] = (string) $value;
            }
        }

        $fields['verify_key'] = implode(',', array_keys($signedFields));

        $fields['verify_sign'] = $this->hash(
            $signedFields,
            array_keys($signedFields)
        );

        return $fields;
    }

    // ---------- Asking the provider ----------

    /** The validation API. Returns the answer only when the provider says the payment is valid. */
    private function validate(string $validationId, string $reference): ?array
    {
        $response = $this->send(fn () => Http::timeout($this->timeout)->get($this->host().'/validator/api/validationserverAPI.php', [
            'val_id' => $validationId,
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'format' => 'json',
        ]));

        $data = $response->json();

        if (! is_array($data) || ! in_array(strtoupper((string) ($data['status'] ?? '')), self::SUCCESS, true)) {
            return null;
        }

        // A valid payment of somebody else cannot be used for this one.
        if (($data['tran_id'] ?? null) !== $reference) {
            throw InvalidWebhookException::malformed('the transaction does not match.');
        }

        return $data;
    }

    /** The transaction query API: everything the provider knows about one transaction id. */
    private function lookup(string $reference): ?array
    {
        $response = $this->send(fn () => Http::timeout($this->timeout)->get($this->host().'/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $reference,
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'format' => 'json',
        ]));

        $data = $response->json();
        $elements = is_array($data) && is_array($data['element'] ?? null)
            ? array_values(array_filter($data['element'], 'is_array'))
            : [];

        if ($elements === []) {
            return null;
        }

        foreach ($elements as $element) {
            if (in_array(strtoupper((string) ($element['status'] ?? '')), self::SUCCESS, true)) {
                return $element;
            }
        }

        return $elements[array_key_last($elements)];
    }

    /** @param array<string, mixed>|null $data */
    private function event(string $reference, ?array $data): WebhookEvent
    {
        if ($data === null) {
            return new WebhookEvent(
                eventId: hash('sha256', "other:{$reference}:none"),
                type: WebhookEvent::OTHER,
                reference: $reference,
                providerReference: null,
                amount: '',
                currency: '',
                reason: 'The provider has no record of this payment.',
                payload: [],
            );
        }

        $status = strtoupper((string) ($data['status'] ?? ''));

        $type = match (true) {
            in_array($status, self::SUCCESS, true) => WebhookEvent::SUCCEEDED,
            $status === 'FAILED' => WebhookEvent::FAILED,
            $status === 'CANCELLED' => WebhookEvent::CANCELLED,
            $status === 'EXPIRED' => WebhookEvent::EXPIRED,
            default => WebhookEvent::OTHER,
        };

        $validationId = $this->text($data['val_id'] ?? null, 100);
        $bank = $this->text($data['bank_tran_id'] ?? null, 100);
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (string) $data['amount'] : '';
        $currency = strtoupper((string) ($data['currency_type'] ?? $data['currency'] ?? ''));

        if ($type === WebhookEvent::SUCCEEDED && ($amount === '' || strlen($currency) !== 3)) {
            throw InvalidWebhookException::malformed('the provider answer has no amount or currency.');
        }

        // The same fact always gets the same id, so the same notification is processed once.
        $eventId = hash('sha256', match ($type) {
            WebhookEvent::SUCCEEDED => "succeeded:{$reference}:".($validationId ?? $bank ?? ''),
            WebhookEvent::OTHER => "other:{$reference}:{$status}",
            default => "{$type}:{$reference}",
        });

        $reason = isset($data['error']) && is_string($data['error']) && $data['error'] !== ''
            ? mb_substr($data['error'], 0, 255)
            : null;

        return new WebhookEvent(
            eventId: $eventId,
            type: $type,
            reference: $reference,
            providerReference: $bank,
            amount: $amount,
            currency: $currency,
            reason: $reason,
            payload: array_diff_key($data, ['card_no' => 1, 'verify_sign' => 1, 'verify_key' => 1]),
            validationId: $validationId,
            cardType: $this->text($data['card_type'] ?? null, 100),
            riskLevel: isset($data['risk_level']) && is_numeric($data['risk_level']) ? (int) $data['risk_level'] : null,
            riskTitle: $this->text($data['risk_title'] ?? null, 100),
        );
    }

    // ---------- The signature ----------
    private function hasValidSignature(Request $request): bool
    {
        $sign = strtolower(trim((string) $request->input('verify_sign')));

        $keys = array_values(array_filter(
            explode(',', (string) $request->input('verify_key')),
            fn (string $key) => $key !== ''
        ));

        if ($sign === '' || $keys === []) {
            return false;
        }

        // Reject duplicate keys.
        if (count($keys) !== count(array_unique($keys))) {
            return false;
        }

        $fields = [];

        foreach ($keys as $key) {
            $value = $request->input($key);

            if (! is_scalar($value)) {
                return false;
            }

            $fields[$key] = (string) $value;
        }

        return hash_equals(
            $this->hash($fields, array_keys($fields)),
            $sign
        );
    }

    /**
     * The method SSLCommerz documents: the listed fields and the md5 of the store password,
     * sorted by name and joined as name=value. (md5 is their choice. This is also why we always ask the provider.)
     *
     * @param  array<string, string>  $fields
     * @param  array<int, string>  $keys
     */
    /**
     * @param  array<string, string>  $fields
     * @param  array<int, string>  $keys
     */
    private function hash(array $fields, array $keys): string
    {
        $data = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }

        $data['store_passwd'] = md5($this->storePassword);

        ksort($data);

        return md5(implode('&', array_map(
            fn ($key, $value) => "{$key}={$value}",
            array_keys($data),
            $data
        )));
    }

    // ---------- Helpers ----------

    /**
     * Every call goes through here. The error of the HTTP library can contain the whole address,
     * and for the checks the address contains the store password, so its text is never passed on.
     */
    private function send(Closure $call): Response
    {
        try {
            $response = $call();
        } catch (Throwable $e) {
            throw new GatewayException('The payment provider could not be reached ('.class_basename($e).').');
        }

        if (! $response->successful()) {
            throw new GatewayException('The payment provider answered with status '.$response->status().'.');
        }

        return $response;
    }

    private function host(): string
    {
        return $this->sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    private function callback(string $name): string
    {
        return "{$this->callbackBase}/api/payments/sslcommerz/{$name}";
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }
}
