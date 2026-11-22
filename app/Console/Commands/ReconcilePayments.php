<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Compare captured payments, refunds, and order payment status';

    public function handle(): int
    {
        $paymentProblems = [];
        $refundProblems = [];
        $orderProblems = [];

        $payments = Payment::query()
            ->withSum(['refunds as reserved_refund_total' => fn ($query) => $query
                ->where('status', '!=', RefundStatus::Failed->value)], 'amount');

        $payments->chunkById(500, function ($chunk) use (&$paymentProblems): void {
            foreach ($chunk as $payment) {
                $paidCents = $payment->paid_amount === null ? null : Money::cents($payment->paid_amount);
                $reservedCents = Money::cents($payment->reserved_refund_total ?? 0);

                if ($paidCents === null && ($payment->status->value === 'succeeded' || $payment->review_reason === 'amount_mismatch')) {
                    $paymentProblems[] = [$payment->reference, 'captured payment has no paid_amount'];
                } elseif ($paidCents !== null && $reservedCents > $paidCents) {
                    $paymentProblems[] = [$payment->reference, Money::format($reservedCents - $paidCents)];
                }
            }
        });

        Refund::query()
            ->with('payment:id,order_id')
            ->chunkById(500, function ($chunk) use (&$refundProblems): void {
                foreach ($chunk as $refund) {
                    if ($refund->order_id !== $refund->payment->order_id) {
                        $refundProblems[] = [$refund->reference, 'refund order does not match payment order'];
                    }

                    if ($refund->status === RefundStatus::Pending && $refund->updated_at->lte(now()->subMinutes(5))) {
                        $refundProblems[] = [$refund->reference, 'refund is still queued; check that a queue worker is running'];
                    } elseif ($refund->status === RefundStatus::Processing
                        && $refund->updated_at->lte(now()->subMinutes(5))) {
                        $refundProblems[] = [$refund->reference, 'provider outcome is unknown; inspect before retrying'];
                    }
                }
            });

        Order::query()
            ->withSum('payments as captured_total', 'paid_amount')
            ->withSum(['refunds as refunded_total' => fn ($query) => $query
                ->where('status', RefundStatus::Succeeded->value)], 'amount')
            ->chunkById(500, function ($chunk) use (&$orderProblems): void {
                foreach ($chunk as $order) {
                    $capturedCents = Money::cents($order->captured_total ?? 0);
                    $refundedCents = Money::cents($order->refunded_total ?? 0);
                    $fullyRefunded = $capturedCents > 0 && $refundedCents >= $capturedCents;

                    if ($refundedCents > $capturedCents) {
                        $orderProblems[] = [$order->number, 'refunds exceed captured payments'];
                    } elseif ($order->payment_method === PaymentMethod::Online
                        && $order->payment_status === PaymentStatus::Paid
                        && $capturedCents === 0) {
                        $orderProblems[] = [$order->number, 'paid order has no captured payment'];
                    } elseif ($order->payment_status === PaymentStatus::RefundPending
                        && $order->status !== OrderStatus::Cancelled) {
                        $orderProblems[] = [$order->number, 'refund-pending order is not cancelled'];
                    } elseif ($capturedCents > 0 && $order->payment_status === PaymentStatus::Unpaid
                        && $order->status !== OrderStatus::Cancelled) {
                        $orderProblems[] = [$order->number, 'unpaid order has captured money'];
                    } elseif ($order->payment_status === PaymentStatus::Refunded && (
                        $order->status !== OrderStatus::Cancelled || ! $fullyRefunded
                    )) {
                        $orderProblems[] = [$order->number, 'refunded status does not match order and payment totals'];
                    } elseif ($fullyRefunded && $order->status === OrderStatus::Cancelled
                        && $order->payment_status !== PaymentStatus::Refunded) {
                        $orderProblems[] = [$order->number, 'all captured money was refunded but order status was not updated'];
                    } elseif ($order->status === OrderStatus::Cancelled && $capturedCents > $refundedCents) {
                        $orderProblems[] = [$order->number, Money::format($capturedCents - $refundedCents)];
                    }
                }
            });

        if ($paymentProblems !== []) {
            $this->error(count($paymentProblems).' payment(s) have inconsistent captured and refund totals.');
            $this->table(['Payment', 'Problem'], $paymentProblems);
        }

        if ($refundProblems !== []) {
            $this->error(count($refundProblems).' refund(s) need attention.');
            $this->table(['Refund', 'Problem'], $refundProblems);
        }

        if ($orderProblems !== []) {
            $this->error(count($orderProblems).' order(s) do not reconcile with captured payments and refunds.');
            $this->table(['Order', 'Problem'], $orderProblems);
        }

        if ($paymentProblems === [] && $refundProblems === [] && $orderProblems === []) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        Log::error('Payment reconcile found problems', [
            'payment_problems' => count($paymentProblems),
            'refund_problems' => count($refundProblems),
            'order_problems' => count($orderProblems),
        ]);

        return self::FAILURE;
    }
}
