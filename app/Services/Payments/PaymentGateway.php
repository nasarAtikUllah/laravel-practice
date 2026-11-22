<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /**
     * Starts the payment at the provider.
     *
     * @return array{session_key: string, checkout_url: string}
     *
     * @throws GatewayException
     */
    public function initiate(Payment $payment): array;

    /**
     * Reads a notification (the provider calling us, or the customer coming back).
     * Nothing in the message is trusted: the provider is asked what really happened.
     *
     * @throws InvalidWebhookException
     * @throws GatewayException
     */
    public function parseWebhook(Request $request, bool $requireSignature = true): WebhookEvent;

    /**
     * Asks the provider what happened to a payment. Null when the provider has no record of it.
     *
     * @throws GatewayException
     */
    public function query(Payment $payment): ?WebhookEvent;

    /**
     * Requests a refund from the provider. A processing response is never retried automatically.
     *
     * @return array{status: string, provider_refund_id: string|null, failure_reason: string|null}
     *
     * @throws GatewayException
     */
    public function refund(Payment $payment, Refund $refund): array;
}
