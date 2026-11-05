<?php

namespace App\Console\Commands;

use App\Enums\RedemptionStatus;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('coupons:reconcile')]
#[Description('Find coupon uses that never ended and coupons used more than their total limit')]
class ReconcileCoupons extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // A hold that should have been released a while ago: the expiry job may have stopped.
        $stuck = CouponRedemption::query()
            ->where('status', RedemptionStatus::Reserved->value)
            ->where('expires_at', '<', now()->subMinutes(5))
            ->count();

        // A hold with no expiry time can never be released by the job.
        $noExpiry = CouponRedemption::query()
            ->where('status', RedemptionStatus::Reserved->value)
            ->whereNull('expires_at')
            ->count();

        $overLimit = Coupon::withTrashed()
            ->whereNotNull('usage_limit_total')
            ->whereRaw(
                '(select count(*) from coupon_redemptions where coupon_redemptions.coupon_id = coupons.id and coupon_redemptions.status in (?, ?)) > coupons.usage_limit_total',
                RedemptionStatus::counting()
            )
            ->get();

        if ($stuck > 0) {
            $this->error("{$stuck} held coupon use(s) expired more than 5 minutes ago and are still active. Is the scheduler running?");
        }

        if ($noExpiry > 0) {
            $this->error("{$noExpiry} held coupon use(s) have no expiry time and will never be released.");
        }

        if ($overLimit->isNotEmpty()) {
            $this->error($overLimit->count().' coupon(s) were used more times than their total limit.');
            $this->table(
                ['Coupon ID', 'Code', 'Total limit', 'Uses'],
                $overLimit->map(fn (Coupon $coupon) => [$coupon->id, $coupon->code, $coupon->usage_limit_total, $coupon->usedCount()])->all()
            );
        }

        if ($stuck === 0 && $noExpiry === 0 && $overLimit->isEmpty()) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        Log::error('Coupon reconcile found problems', [
            'stuck_holds' => $stuck,
            'holds_without_expiry' => $noExpiry,
            'coupons_over_limit' => $overLimit->count(),
        ]);

        return self::FAILURE;
    }
}
