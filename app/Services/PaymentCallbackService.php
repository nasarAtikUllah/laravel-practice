<?php

namespace App\Services;

use App\Enums\PaymentRecordStatus;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Payments\CallbackThrottled;
use App\Services\Payments\GatewayException;
use App\Services\Payments\InvalidWebhookException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class PaymentCallbackService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentService $payments,
    ) {}

    public static function perReferenceLimit(): int
    {
        $limit = (int) config('payments.callback_attempts_per_reference', 10);

        return $limit > 0 ? $limit : 10;
    }

    /**
     * A notification from the provider, or the customer coming back from the provider.
     * Nothing in it is trusted: it only tells us which payment to look at.
     *
     * @return array{outcome: string, payment: Payment}
     *
     * @throws InvalidWebhookException
     * @throws PaymentException
     * @throws CallbackThrottled
     * @throws GatewayException
     */
    public function handle(Request $request, bool $signed): array
    {
        $reference = $request->input('tran_id');

        if (! is_string($reference) || $reference === '' || strlen($reference) > 40) {
            throw InvalidWebhookException::malformed('tran_id is missing or not valid.');
        }

        // We only talk to the provider about payments we started.
        $payment = Payment::query()->where('reference', $reference)->first() ?? throw PaymentException::notFound();

        // One payment cannot make us call the provider again and again.
        $key = 'payment-callback:'.$reference;

        if (RateLimiter::tooManyAttempts($key, self::perReferenceLimit())) {
            throw new CallbackThrottled(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $event = $this->gateways->driver($payment->provider)->parseWebhook($request, $signed);

        return ['outcome' => $this->apply($payment, $event), 'payment' => $payment->refresh()];
    }

    /** Asks the provider about one payment and applies the answer. Null when the provider has no record. */
    public function sync(Payment $payment): ?string
    {
        $event = $this->gateways->driver($payment->provider)->query($payment);

        return $event === null ? null : $this->apply($payment, $event);
    }

    /**
     * Saves the event and acts on it in one transaction.
     * The same event is processed once: a repeat gives 'duplicate'.
     * If acting on it fails, the event is not saved either, so the next try can start again.
     */
    public function apply(Payment $payment, WebhookEvent $event): string
    {
        return DB::transaction(function () use ($payment, $event) {
            try {
                $record = PaymentEvent::create([
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'event_id' => $event->eventId,
                    'type' => $event->type,
                    'payload' => $event->payload,
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'duplicate';
            }

            $outcome = match ($event->type) {
                WebhookEvent::SUCCEEDED => $this->payments->applySucceeded($event),
                WebhookEvent::FAILED => $this->payments->applyFailed($event->reference, $event->reason),
                WebhookEvent::CANCELLED => $this->payments->applyFailed($event->reference, $event->reason, PaymentRecordStatus::Cancelled),
                WebhookEvent::EXPIRED => $this->payments->applyFailed($event->reference, $event->reason, PaymentRecordStatus::Expired),
                default => 'ignored',
            };

            $record->update(['result' => $outcome]);

            return $outcome;
        });
    }
}
