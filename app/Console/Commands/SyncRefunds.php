<?php

namespace App\Console\Commands;

use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Console\Command;
use Throwable;

class SyncRefunds extends Command
{
    protected $signature = 'refunds:sync
        {reference? : Check one refund, even if it was updated recently}
        {--limit=100 : The most processing refunds to check in one run}';

    protected $description = 'Ask the payment provider for the status of processing refunds';

    public function handle(RefundService $refundService): int
    {
        $reference = $this->argument('reference');

        if ($reference !== null) {
            $refund = Refund::query()->where('reference', (string) $reference)->first();

            if ($refund === null) {
                $this->error('Refund not found.');

                return self::FAILURE;
            }

            $refunds = collect([$refund]);
        } else {
            $refunds = Refund::query()
                ->where('status', RefundStatus::Processing->value)
                ->where('updated_at', '<=', now()->subMinutes(5))
                ->orderBy('id')
                ->limit(max(1, (int) $this->option('limit')))
                ->get();
        }

        $counts = ['succeeded' => 0, 'failed' => 0, 'processing' => 0, 'unchanged' => 0, 'errors' => 0];

        foreach ($refunds as $refund) {
            try {
                $status = $refundService->sync($refund);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Refund {$refund->reference} could not be checked.");
                $counts['errors']++;

                continue;
            }

            match ($status) {
                RefundStatus::Succeeded => $counts['succeeded']++,
                RefundStatus::Failed => $counts['failed']++,
                RefundStatus::Processing => $counts['processing']++,
                RefundStatus::Pending => $counts['unchanged']++,
                null => $counts['unchanged']++,
            };
        }

        $this->info(sprintf(
            'Checked %d refund(s): %d succeeded, %d failed, %d processing, %d unchanged, %d could not be checked.',
            $refunds->count(),
            $counts['succeeded'],
            $counts['failed'],
            $counts['processing'],
            $counts['unchanged'],
            $counts['errors'],
        ));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
