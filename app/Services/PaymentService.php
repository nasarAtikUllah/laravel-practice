<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\CouponException;
use App\Exceptions\PaymentException;
use App\Exceptions\ReservationNotActiveException;
use App\Models\CouponRedemption;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WebhookEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly OrderService $orders,
    ) {}

    public static function maxAttempts(): int
    {
        $max = (int) config('payments.max_attempts', 3);

        return $max > 0 ? $max : 3;
    }

    // ---------- Starting a payment ----------

    /**
     * Starts a payment for an online order. While one attempt is open, the same one is returned.
     * The attempt is saved first and the provider is called after the lock ended,
     * so a slow provider never holds the order row.
     */
    public function initiate(Order $order): Payment
    {
        $payment = DB::transaction(function () use ($order) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            $this->assertPayable($locked);

            $open = Payment::query()
                ->where('order_id', $locked->id)
                ->where('status', PaymentRecordStatus::Initiated->value)
                ->latest('id')
                ->first();

            if ($open !== null) {
                return $open;
            }

            if (Payment::where('order_id', $locked->id)->count() >= self::maxAttempts()) {
                throw PaymentException::tooManyAttempts();
            }

            return Payment::create([
                'order_id' => $locked->id,
                'provider' => (string) config('payments.default', 'sslcommerz'),
                'reference' => 'P'.$locked->id.'-'.Str::upper(Str::random(10)),
                'amount' => $locked->grand_total,
                'currency' => $locked->currency,
                'status' => PaymentRecordStatus::Initiated,
                'expires_at' => $locked->expires_at,
            ]);
        });

        // An attempt that was saved but never reached the provider is started now.
        return $payment->checkout_url !== null ? $payment : $this->start($payment);
    }

    private function assertPayable(Order $order): void
    {
        if ($order->payment_method !== PaymentMethod::Online) {
            throw PaymentException::notOnline();
        }

        if ($order->status !== OrderStatus::PendingPayment || $order->payment_status !== PaymentStatus::Unpaid) {
            throw PaymentException::notWaitingForPayment();
        }

        if ($order->expires_at === null || $order->expires_at->lte(now())) {
            throw PaymentException::holdEnded();
        }

        $cents = Money::cents($order->grand_total);
        $min = Money::cents(config('payments.min_amount', 10));
        $max = Money::cents(config('payments.max_amount', 500000));

        if ($cents < $min) {
            throw PaymentException::amountTooSmall(Money::format($min));
        }

        if ($max > 0 && $cents > $max) {
            throw PaymentException::amountTooLarge(Money::format($max));
        }
    }

    private function start(Payment $payment): Payment
    {
        try {
            $result = $this->gateways->driver($payment->provider)->initiate($payment);
        } catch (Throwable $e) {
            report($e);

            $payment->update([
                'status' => PaymentRecordStatus::Failed,
                'failure_reason' => 'gateway_error',
                'failed_at' => now(),
            ]);

            throw PaymentException::gatewayUnavailable();
        }

        $payment->update([
            'session_key' => $result['session_key'],
            'checkout_url' => $result['checkout_url'],
        ]);

        return $payment->refresh();
    }

    // ---------- What the provider tells us ----------

    /**
     * The provider says the money arrived. Returns what happened, as a short code.
     * Safe to call again for the same payment.
     */
    public function applySucceeded(WebhookEvent $event): string
    {
        return DB::transaction(function () use ($event) {
            $payment = $this->lockPayment($event->reference);
            $order = Order::lockForUpdate()->findOrFail($payment->order_id);

            if ($payment->status === PaymentRecordStatus::Succeeded) {
                return 'already_succeeded';
            }

            // The money does not match the order. It is never used to confirm it.
            if (Money::cents($event->amount) !== Money::cents($payment->amount)
                || strtoupper($event->currency) !== strtoupper($payment->currency)) {
                $payment->update([
                    'status' => PaymentRecordStatus::Failed,
                    'failure_reason' => 'The amount paid did not match the order.',
                    'failed_at' => now(),
                    'review_reason' => 'amount_mismatch',
                ] + $this->details($event));

                return 'amount_mismatch';
            }

            // The provider is the authority, so an attempt we closed can still turn out to be paid.
            if ($order->status === OrderStatus::PendingPayment && $order->payment_status === PaymentStatus::Unpaid) {
                try {
                    // All or nothing: if the stock or the coupon was already given back, nothing is kept.
                    DB::transaction(fn () => $this->confirm($payment, $order, $event));

                    return 'confirmed';
                } catch (ReservationNotActiveException|CouponException) {
                    return $this->acceptWithoutOrder($payment->refresh(), $order->refresh(), $event, 'late_payment');
                }
            }

            return $this->acceptWithoutOrder(
                $payment,
                $order,
                $event,
                $order->status === OrderStatus::Cancelled ? 'order_cancelled' : 'duplicate_payment',
            );
        });
    }

    /**
     * The provider says the attempt did not go through (failed, cancelled by the customer, or expired).
     * The order keeps its hold, so the customer can try again.
     */
    public function applyFailed(string $reference, ?string $reason = null, PaymentRecordStatus $as = PaymentRecordStatus::Failed): string
    {
        return DB::transaction(function () use ($reference, $reason, $as) {
            $payment = $this->lockPayment($reference);

            return match ($payment->status) {
                PaymentRecordStatus::Succeeded => 'ignored_after_success',
                PaymentRecordStatus::Failed => 'already_failed',
                PaymentRecordStatus::Cancelled, PaymentRecordStatus::Expired => 'ignored',
                PaymentRecordStatus::Initiated => $this->closeAttempt($payment, $reason, $as),
            };
        });
    }

    // ---------- Inside the transactions ----------

    private function confirm(Payment $payment, Order $order, WebhookEvent $event): void
    {
        // The held stock becomes a sale. Variants are always handled in the same order.
        $reservations = InventoryReservation::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->orderBy('product_variant_id')
            ->get();

        if ($reservations->isEmpty()) {
            throw new ReservationNotActiveException;
        }

        $reservations->each(fn (InventoryReservation $reservation) => $this->inventory->commitReservation($reservation));

        // The held coupon use becomes a counted use. If the coupon use was given back, nothing is kept.
        if ($order->coupon_code !== null) {
            $held = CouponRedemption::query()
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->whereIn('status', RedemptionStatus::counting())
                ->orderBy('id')
                ->get();

            if ($held->isEmpty()) {
                throw CouponException::useEnded();
            }

            $held->where('status', RedemptionStatus::Reserved)
                ->each(fn (CouponRedemption $redemption) => $this->coupons->commit($redemption));
        }

        $payment->update([
            'status' => PaymentRecordStatus::Succeeded,
            'paid_at' => now(),
            'failure_reason' => null,
            'review_reason' => null,
        ] + $this->details($event));

        $order->update([
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now(),
            'expires_at' => null,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::PendingPayment,
            'to_status' => OrderStatus::Confirmed,
            'user_id' => null,
            'note' => $event->riskLevel === 1
                ? 'Payment received. The payment provider marked it as risky. Check it before shipping.'
                : 'Payment received',
        ]);
    }

    /** Money arrived that cannot confirm the order. It is recorded and flagged for a refund. */
    private function acceptWithoutOrder(Payment $payment, Order $order, WebhookEvent $event, string $reviewReason): string
    {
        if ($order->status === OrderStatus::PendingPayment) {
            // The time to pay ended: close the order and give back what it held.
            $this->orders->cancel($order, null, 'Payment arrived after the hold expired');
            $order->refresh();
        }

        $payment->update([
            'status' => PaymentRecordStatus::Succeeded,
            'paid_at' => now(),
            'failure_reason' => null,
            'review_reason' => $reviewReason,
        ] + $this->details($event));

        // The money is with us, so the order shows it. A refund is made later (Day 4).
        if ($order->payment_status === PaymentStatus::Unpaid) {
            $order->update(['payment_status' => PaymentStatus::Paid]);
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'user_id' => null,
            'note' => $reviewReason === 'duplicate_payment'
                ? 'A second payment was received for this order. Refund pending.'
                : 'Payment received after the order was cancelled. Refund pending.',
        ]);

        return $reviewReason;
    }

    private function closeAttempt(Payment $payment, ?string $reason, PaymentRecordStatus $as): string
    {
        $reason = trim((string) $reason);

        $default = match ($as) {
            PaymentRecordStatus::Cancelled => 'The payment was cancelled.',
            PaymentRecordStatus::Expired => 'The payment session expired.',
            default => 'The payment was not completed.',
        };

        $payment->update([
            'status' => $as,
            'failure_reason' => $reason === '' ? $default : mb_substr($reason, 0, 255),
            'failed_at' => now(),
        ]);

        return $as->value;
    }

    /** @return array<string, mixed> what the provider told us about the payment */
    private function details(WebhookEvent $event): array
    {
        return array_filter([
            'provider_reference' => $event->providerReference,
            'validation_id' => $event->validationId,
            'card_type' => $event->cardType,
            'risk_level' => $event->riskLevel,
        ], fn ($value) => $value !== null);
    }

    private function lockPayment(string $reference): Payment
    {
        return Payment::query()->where('reference', $reference)->lockForUpdate()->first()
            ?? throw PaymentException::notFound();
    }
}
