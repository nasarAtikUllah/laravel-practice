<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentCallbackService;
use Illuminate\Console\Command;
use Throwable;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire {--limit=200 : Maximum orders to process}';

    protected $description = 'Cancel expired unpaid orders after checking open online payments';

    public function handle(PaymentCallbackService $callbacks, OrderService $orders): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $expired = Order::query()
            ->whereIn('status', [OrderStatus::PendingPayment->value, OrderStatus::PendingConfirmation->value])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $cancelled = 0;
        $checked = 0;
        $errors = 0;

        foreach ($expired as $order) {
            $openPayments = Payment::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [
                    PaymentRecordStatus::Initiated->value,
                    PaymentRecordStatus::Failed->value,
                    PaymentRecordStatus::Cancelled->value,
                    PaymentRecordStatus::Expired->value,
                ])
                ->orderBy('id')
                ->get();

            $providerCheckFailed = false;

            foreach ($openPayments as $payment) {
                try {
                    $callbacks->sync($payment);
                } catch (Throwable $exception) {
                    report($exception);
                    $providerCheckFailed = true;
                    $errors++;
                    $this->warn("Order {$order->number} was not expired because its payment could not be checked.");

                    break;
                }
            }

            if ($providerCheckFailed) {
                continue;
            }

            $fresh = $order->fresh();

            if (
                $fresh === null
                || ! in_array($fresh->status, [OrderStatus::PendingPayment, OrderStatus::PendingConfirmation], true)
                || $fresh->payment_status === PaymentStatus::Paid
                || $fresh->expires_at === null
                || $fresh->expires_at->gt(now())
            ) {
                $checked++;

                continue;
            }

            try {
                $orders->cancel($fresh, null, 'Order expired before payment or confirmation');
                $cancelled++;
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
                $this->warn("Order {$order->number} could not be expired.");
            }

            $checked++;
        }

        $this->info("Checked {$checked} expired order(s), cancelled {$cancelled}, {$errors} could not be processed.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
