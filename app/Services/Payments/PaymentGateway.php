<?php

namespace App\Services\Payments;

use App\Models\Payment;
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
}
