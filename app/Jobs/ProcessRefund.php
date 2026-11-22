<?php

namespace App\Jobs;

use App\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $refundId) {}

    public function handle(RefundService $refunds): void
    {
        $refunds->process($this->refundId);
    }
}
