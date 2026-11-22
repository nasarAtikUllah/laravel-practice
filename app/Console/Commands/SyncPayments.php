<?php

namespace App\Console\Commands;

use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use App\Services\PaymentCallbackService;
use Illuminate\Console\Command;
use Throwable;

class SyncPayments extends Command
{
    protected $signature = 'payments:sync
        {reference? : Check one payment, even a young one}
        {--hours=24 : How far back open payments are checked}
        {--late-minutes=60 : How long closed payments are still checked, for a customer who paid late}
        {--limit=100 : The most payments to check in one run}';

    protected $description = 'Ask the payment provider about payments that never got a notification';

    public function handle(PaymentCallbackService $callbacks): int
    {
        $reference = $this->argument('reference');

        if ($reference !== null) {
            $payment = Payment::query()->where('reference', (string) $reference)->first();

            if ($payment === null) {
                $this->error('Payment not found.');

                return self::FAILURE;
            }

            $payments = collect([$payment]);
        } else {
            $hours = max(1, (int) $this->option('hours'));
            $late = max(1, (int) $this->option('late-minutes'));
            $limit = max(1, (int) $this->option('limit'));

            $payments = Payment::query()
                ->whereNotNull('checkout_url')
                ->whereNull('review_reason')
                // Give the notification a moment to arrive first.
                ->where('created_at', '<=', now()->subMinutes(2))
                ->where(fn ($q) => $q
                    ->where(fn ($open) => $open
                        ->where('status', PaymentRecordStatus::Initiated->value)
                        ->where('created_at', '>=', now()->subHours($hours)))
                    ->orWhere(fn ($closed) => $closed
                        ->whereIn('status', [
                            PaymentRecordStatus::Failed->value,
                            PaymentRecordStatus::Cancelled->value,
                            PaymentRecordStatus::Expired->value,
                        ])
                        ->where('created_at', '>=', now()->subMinutes($late))))
                ->orderBy('id')
                ->limit($limit)
                ->get();
        }

        $counts = ['confirmed' => 0, 'flagged' => 0, 'closed' => 0, 'unchanged' => 0, 'errors' => 0];

        foreach ($payments as $payment) {
            if ($payment->status === PaymentRecordStatus::Succeeded && $payment->review_reason === null) {
                $counts['unchanged']++;

                continue;
            }

            try {
                $outcome = $callbacks->sync($payment);
            } catch (Throwable $e) {
                // The messages of the gateway are safe to log: they never contain the store password.
                report($e);
                $this->warn("Payment {$payment->reference} could not be checked.");
                $counts['errors']++;

                continue;
            }

            match ($outcome) {
                'confirmed' => $counts['confirmed']++,
                'late_payment', 'order_cancelled', 'duplicate_payment', 'amount_mismatch' => $counts['flagged']++,
                'failed', 'cancelled', 'expired' => $counts['closed']++,
                default => $counts['unchanged']++,
            };
        }

        $this->info(sprintf(
            'Checked %d payment(s): %d confirmed, %d flagged for refund, %d closed, %d unchanged, %d could not be checked.',
            $payments->count(),
            $counts['confirmed'],
            $counts['flagged'],
            $counts['closed'],
            $counts['unchanged'],
            $counts['errors'],
        ));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
