<?php

namespace App\Console\Commands;

use App\Services\CouponService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('coupons:release-expired')]
#[Description('Release coupon uses held by unpaid orders that have expired')]
class ReleaseExpiredCoupons extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CouponService $coupons): int
    {
        $count = $coupons->releaseExpired();

        $this->info("Released {$count} expired coupon use(s).");

        return self::SUCCESS;
    }
}
