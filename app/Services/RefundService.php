<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\RefundException;
use App\Jobs\ProcessRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\GatewayException;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function request(
        Payment $payment,
        int $amountCents,
        string $reason,
        ?User $requestedBy,
        string $idempotencyKey,
    ): Refund {
        $refund = DB::transaction(function () use ($payment, $amountCents, $reason, $requestedBy, $idempotencyKey) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $existing = Refund::query()
                ->where('payment_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (
                    Money::cents($existing->amount) !== $amountCents
                    || $existing->reason !== mb_substr(trim($reason), 0, 255)
                ) {
                    throw RefundException::idempotencyConflict();
                }

                return $existing;
            }

            $paidCents = Money::cents($locked->paid_amount);

            if ($paidCents < 1) {
                throw RefundException::notRefundable();
            }

            $reservedCents = Money::cents($locked->refunds()
                ->where('status', '!=', RefundStatus::Failed->value)
                ->sum('amount'));

            if ($amountCents < 1 || $reservedCents + $amountCents > $paidCents) {
                throw RefundException::amountUnavailable();
            }

            $refund = Refund::create([
                'payment_id' => $locked->id,
                'order_id' => $locked->order_id,
                'reference' => 'R'.Str::upper(Str::random(29)),
                'amount' => Money::format($amountCents),
                'status' => RefundStatus::Pending,
                'reason' => mb_substr(trim($reason), 0, 255),
                'requested_by' => $requestedBy?->id,
                'attempts' => 0,
                'idempotency_key' => $idempotencyKey,
            ]);

            $orderId = $locked->order_id;

            DB::afterCommit(fn () => Order::query()
                ->whereKey($orderId)
                ->where('status', 'cancelled')
                ->update(['payment_status' => PaymentStatus::RefundPending->value]));

            return $refund;
        });

        if ($refund->status === RefundStatus::Pending) {
            ProcessRefund::dispatch($refund->id)->afterCommit();
        }

        return $refund->refresh();
    }

    public function retry(Refund $refund): Refund
    {
        $pending = DB::transaction(function () use ($refund) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::Failed) {
                throw RefundException::cannotRetry();
            }

            $reservedCents = Money::cents($payment->refunds()
                ->where('status', '!=', RefundStatus::Failed->value)
                ->sum('amount'));

            if ($reservedCents + Money::cents($locked->amount) > Money::cents($payment->paid_amount)) {
                throw RefundException::amountUnavailable();
            }

            $locked->update([
                'status' => RefundStatus::Pending,
                'failure_reason' => null,
                'processed_at' => null,
            ]);

            $orderId = $locked->order_id;

            DB::afterCommit(fn () => Order::query()
                ->whereKey($orderId)
                ->where('status', 'cancelled')
                ->update(['payment_status' => PaymentStatus::RefundPending->value]));

            return $locked;
        });

        ProcessRefund::dispatch($pending->id)->afterCommit();

        return $pending->refresh();
    }

    public function resolve(
        Refund $refund,
        RefundStatus $status,
        User $resolvedBy,
        string $resolutionNote,
        ?string $providerRefundId,
        ?string $failureReason,
    ): Refund {
        if (! in_array($status, [RefundStatus::Succeeded, RefundStatus::Failed], true)) {
            throw RefundException::cannotResolve();
        }

        return DB::transaction(function () use ($refund, $status, $resolvedBy, $resolutionNote, $providerRefundId, $failureReason) {
            Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::Processing) {
                throw RefundException::cannotResolve();
            }

            $locked->update([
                'status' => $status,
                'provider_refund_id' => $providerRefundId ?? $locked->provider_refund_id,
                'failure_reason' => $status === RefundStatus::Failed ? $failureReason : null,
                'resolved_by' => $resolvedBy->id,
                'resolution_note' => trim($resolutionNote),
                'resolved_at' => now(),
                'processed_at' => now(),
            ]);

            $this->syncCancelledOrderPaymentStatus($locked->order_id);

            return $locked;
        });
    }

    public function sync(Refund $refund): ?RefundStatus
    {
        $refund->loadMissing('payment');

        if ($refund->status !== RefundStatus::Processing) {
            return null;
        }

        $result = $this->gateways
            ->driver($refund->payment->provider)
            ->queryRefund($refund);

        return DB::transaction(function () use ($refund, $result): ?RefundStatus {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::Processing) {
                return null;
            }

            $status = RefundStatus::from($result['status']);
            $locked->update([
                'status' => $status,
                'provider_refund_id' => $result['provider_refund_id'] ?? $locked->provider_refund_id,
                'failure_reason' => $result['failure_reason'],
                'processed_at' => $status === RefundStatus::Processing ? null : now(),
            ]);
            $locked->touch();

            $this->syncCancelledOrderPaymentStatus($locked->order_id);

            return $status;
        });
    }

    public function process(int $refundId): void
    {
        $refund = DB::transaction(function () use ($refundId) {
            $locked = Refund::query()->with('payment')->lockForUpdate()->findOrFail($refundId);

            if ($locked->status !== RefundStatus::Pending) {
                return null;
            }

            if (trim((string) $locked->payment->provider_reference) === '') {
                $locked->update([
                    'status' => RefundStatus::Failed,
                    'failure_reason' => 'The provider transaction reference is missing.',
                    'processed_at' => now(),
                ]);

                return null;
            }

            $locked->update([
                'status' => RefundStatus::Processing,
                'attempts' => $locked->attempts + 1,
            ]);

            return $locked;
        });

        if ($refund === null) {
            return;
        }

        try {
            $gateway = $this->gateways->driver($refund->payment->provider);
        } catch (GatewayException $exception) {
            report($exception);

            return;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            report($exception);
            $this->failBeforeProviderCall($refund->id);

            return;
        }

        try {
            $result = $gateway->refund($refund->payment, $refund);
        } catch (GatewayException $exception) {
            report($exception);

            return;
        }

        DB::transaction(function () use ($refund, $result) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::Processing) {
                return;
            }

            $status = RefundStatus::from($result['status']);
            $locked->update([
                'status' => $status,
                'provider_refund_id' => $result['provider_refund_id'],
                'failure_reason' => $result['failure_reason'],
                'processed_at' => $status === RefundStatus::Processing ? null : now(),
            ]);

            $this->syncCancelledOrderPaymentStatus($locked->order_id);
        });
    }

    private function failBeforeProviderCall(int $refundId): void
    {
        DB::transaction(function () use ($refundId): void {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refundId);

            if ($refund->status === RefundStatus::Processing) {
                $refund->update([
                    'status' => RefundStatus::Failed,
                    'failure_reason' => 'The payment gateway is not configured for this refund.',
                    'processed_at' => now(),
                ]);

                $this->syncCancelledOrderPaymentStatus($refund->order_id);
            }
        });
    }

    private function syncCancelledOrderPaymentStatus(int $orderId): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);

        if ($order->status !== OrderStatus::Cancelled) {
            return;
        }

        $capturedCents = Money::cents($order->payments()->sum('paid_amount'));

        if ($capturedCents < 1) {
            return;
        }

        $refundedCents = Money::cents($order->refunds()
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount'));

        $hasUnresolvedRefund = $order->refunds()
            ->whereIn('status', [
                RefundStatus::Pending->value,
                RefundStatus::Processing->value,
                RefundStatus::Failed->value,
            ])
            ->exists();

        $paymentStatus = match (true) {
            $refundedCents >= $capturedCents => PaymentStatus::Refunded,
            $hasUnresolvedRefund => PaymentStatus::RefundPending,
            $refundedCents > 0 => PaymentStatus::PartiallyRefunded,
            default => null,
        };

        if ($paymentStatus !== null && $order->payment_status !== $paymentStatus) {
            $order->update(['payment_status' => $paymentStatus]);
        }
    }
}
