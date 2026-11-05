<?php

use App\Enums\ProductStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\CouponException;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\CouponRedemption;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->coupons = app(CouponService::class);
    $this->user = User::factory()->create();
});

// ---------- Quote: the discount ----------

it('calculates a percentage discount', function () {
    $variant = buyable(1200);
    makeCoupon(['code' => 'SAVE10', 'value' => 10]);

    $quote = $this->coupons->quote('SAVE10', cartOf([[$variant, 2]]), $this->user);

    expect($quote['type'])->toBe('percentage');
    expect($quote['items_total'])->toBe('2400.00');
    expect($quote['discount'])->toBe('240.00');
    expect($quote['total_after_discount'])->toBe('2160.00');
});

it('calculates a fixed discount', function () {
    $variant = buyable(1200);
    makeCoupon(['code' => 'MINUS200', 'type' => 'fixed', 'value' => 200]);

    $quote = $this->coupons->quote('MINUS200', cartOf([[$variant, 2]]), $this->user);

    expect($quote['discount'])->toBe('200.00');
    expect($quote['total_after_discount'])->toBe('2200.00');
});

it('caps a percentage discount at the maximum discount', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'HALF', 'value' => 50, 'max_discount' => 300]);

    $quote = $this->coupons->quote('HALF', cartOf([[$variant, 2]]), $this->user);

    expect($quote['discount'])->toBe('300.00');
});

it('never gives more discount than the order total', function () {
    $variant = buyable(1500);
    makeCoupon(['code' => 'BIG', 'type' => 'fixed', 'value' => 5000]);

    $quote = $this->coupons->quote('BIG', cartOf([[$variant, 1]]), $this->user);

    expect($quote['discount'])->toBe('1500.00');
    expect($quote['total_after_discount'])->toBe('0.00');
});

it('allows a 100 percent coupon', function () {
    $variant = buyable(800);
    makeCoupon(['code' => 'FREE', 'value' => 100]);

    $quote = $this->coupons->quote('FREE', cartOf([[$variant, 1]]), $this->user);

    expect($quote['total_after_discount'])->toBe('0.00');
});

it('rounds half a cent up', function () {
    $variant = buyable(0.10);
    makeCoupon(['code' => 'TINY', 'value' => 5]); // 5% of 0.10 = 0.005

    $quote = $this->coupons->quote('TINY', cartOf([[$variant, 1]]), $this->user);

    expect($quote['discount'])->toBe('0.01');
});

it('refuses a coupon that gives no discount', function () {
    $variant = buyable(0.10);
    makeCoupon(['code' => 'ONE', 'value' => 1]); // 1% of 0.10 rounds to 0

    expect(fn () => $this->coupons->quote('ONE', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class, 'This coupon does not give a discount on this order.');
});

it('applies the discount to the selling price, not the compare price', function () {
    $variant = buyable(1200, ['compare_price' => 1500]);
    makeCoupon(['code' => 'SAVE10', 'value' => 10]);

    $quote = $this->coupons->quote('SAVE10', cartOf([[$variant, 1]]), $this->user);

    expect($quote['items_total'])->toBe('1200.00');
    expect($quote['discount'])->toBe('120.00');
});

it('splits the discount over the lines and gives left-over cents to the last line', function () {
    $a = buyable(10);
    $b = buyable(10);
    $c = buyable(10);
    makeCoupon(['code' => 'ONEBUCK', 'type' => 'fixed', 'value' => 1]);

    $quote = $this->coupons->quote('ONEBUCK', cartOf([[$a, 1], [$b, 1], [$c, 1]]), $this->user);

    expect(array_column($quote['lines'], 'discount'))->toBe(['0.33', '0.33', '0.34']);
    expect(array_column($quote['lines'], 'line_payable'))->toBe(['9.67', '9.67', '9.66']);
    expect($quote['discount'])->toBe('1.00');
});

it('never gives a line more discount than its own price', function () {
    $big = buyable(1000);
    $tiny = buyable(0.01);
    makeCoupon(['code' => 'FIX', 'type' => 'fixed', 'value' => 999.99]);

    $quote = $this->coupons->quote('FIX', cartOf([[$big, 1], [$tiny, 1]]), $this->user);

    foreach ($quote['lines'] as $line) {
        expect((float) $line['discount'])->toBeLessThanOrEqual((float) $line['line_total']);
    }

    $sum = array_sum(array_map(fn ($line) => (float) $line['discount'], $quote['lines']));

    expect(round($sum, 2))->toBe(999.99);
});

it('merges lines of the same variant', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $quote = $this->coupons->quote('SAVE10', [
        ['variant_id' => $variant->id, 'quantity' => 1],
        ['variant_id' => $variant->id, 'quantity' => 2],
    ], $this->user);

    expect($quote['lines'])->toHaveCount(1);
    expect($quote['lines'][0]['quantity'])->toBe(3);
    expect($quote['items_total'])->toBe('3000.00');
});

it('treats a code in small letters with spaces as the same code', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $quote = $this->coupons->quote('  save10 ', cartOf([[$variant, 1]]), $this->user);

    expect($quote['code'])->toBe('SAVE10');
});

it('does not use up the coupon when quoting', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $this->coupons->quote('SAVE10', cartOf([[$variant, 1]]), $this->user);
    $this->coupons->quote('SAVE10', cartOf([[$variant, 1]]), $this->user);

    expect(CouponRedemption::count())->toBe(0);
});

// ---------- Quote: the minimum order ----------

it('refuses an order below the minimum and names the amount', function () {
    $variant = buyable(400);
    makeCoupon(['code' => 'BIGONLY', 'min_order_amount' => 500]);

    expect(fn () => $this->coupons->quote('BIGONLY', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class, 'The order total must be at least 500.00 to use this coupon.');
});

it('accepts an order exactly at the minimum', function () {
    $variant = buyable(500);
    makeCoupon(['code' => 'BIGONLY', 'min_order_amount' => 500]);

    $quote = $this->coupons->quote('BIGONLY', cartOf([[$variant, 1]]), $this->user);

    expect($quote['discount'])->toBe('50.00');
});

// ---------- Quote: who can use a coupon and when ----------

it('gives the same message for every reason a code cannot be used', function (string $case) {
    $variant = buyable(1000);

    match ($case) {
        'unknown' => null,
        'inactive' => makeCoupon(['code' => 'THECODE', 'is_active' => false]),
        'expired' => makeCoupon(['code' => 'THECODE', 'starts_at' => now()->subDays(5), 'expires_at' => now()->subDay()]),
        'scheduled' => makeCoupon(['code' => 'THECODE', 'starts_at' => now()->addDay()]),
        'deleted' => makeCoupon(['code' => 'THECODE'])->delete(),
        'limit reached' => makeRedemption(makeCoupon(['code' => 'THECODE', 'usage_limit_total' => 1])),
    };

    expect(fn () => $this->coupons->quote('THECODE', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class, 'This coupon code is not valid.');
})->with(['unknown', 'inactive', 'expired', 'scheduled', 'deleted', 'limit reached']);

it('is valid exactly at the start time and not before', function () {
    $variant = buyable(1000);
    $start = now()->addHour()->startOfSecond();
    makeCoupon(['code' => 'LATER', 'starts_at' => $start]);

    expect(fn () => $this->coupons->quote('LATER', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class);

    $this->travelTo($start);

    expect($this->coupons->quote('LATER', cartOf([[$variant, 1]]), $this->user)['discount'])->toBe('100.00');
});

it('is valid one second before the expiry time and not valid at the expiry time', function () {
    $variant = buyable(1000);
    $end = now()->addHour()->startOfSecond();
    makeCoupon(['code' => 'SOON', 'expires_at' => $end]);

    $this->travelTo($end->copy()->subSecond());

    expect($this->coupons->quote('SOON', cartOf([[$variant, 1]]), $this->user)['discount'])->toBe('100.00');

    $this->travelTo($end);

    expect(fn () => $this->coupons->quote('SOON', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class);
});

it('stops a customer who used up their own limit but not other customers', function () {
    $variant = buyable(1000);
    $coupon = makeCoupon(['code' => 'ONCE', 'usage_limit_per_user' => 1]);
    makeRedemption($coupon, $this->user);

    expect(fn () => $this->coupons->quote('ONCE', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class);

    $other = User::factory()->create();

    expect($this->coupons->quote('ONCE', cartOf([[$variant, 1]]), $other)['discount'])->toBe('100.00');
});

it('does not count released or expired uses against the limit', function () {
    $variant = buyable(1000);
    $coupon = makeCoupon(['code' => 'ONCE', 'usage_limit_per_user' => 1]);
    makeRedemption($coupon, $this->user, ['status' => 'released']);
    makeRedemption($coupon, $this->user, ['status' => 'expired']);

    expect($this->coupons->quote('ONCE', cartOf([[$variant, 1]]), $this->user)['discount'])->toBe('100.00');
});

// ---------- Quote: the items ----------

it('refuses items that cannot be bought', function (string $case) {
    makeCoupon(['code' => 'SAVE10']);

    $variant = match ($case) {
        'missing' => null,
        'draft product' => ProductVariant::factory()->create([
            'product_id' => makeProduct(['status' => ProductStatus::Draft], 0)->id,
            'price' => 1000,
        ]),
        'inactive brand' => ProductVariant::factory()->create([
            'product_id' => makeProduct([
                'collection_id' => Collection::factory()->create(['brand_id' => Brand::factory()->inactive()->create()->id])->id,
            ], 0)->id,
            'price' => 1000,
        ]),
        'deleted variant' => tap(buyable(1000))->delete(),
    };

    $items = [['variant_id' => $variant?->id ?? 999, 'quantity' => 1]];

    expect(fn () => $this->coupons->quote('SAVE10', $items, $this->user))
        ->toThrow(CouponException::class, 'One or more items are not available.');
})->with(['missing', 'draft product', 'inactive brand', 'deleted variant']);

it('refuses an order that is too large', function () {
    $variant = buyable(9999999999);
    makeCoupon(['code' => 'SAVE10']);

    expect(fn () => $this->coupons->quote('SAVE10', cartOf([[$variant, 1]]), $this->user))
        ->toThrow(CouponException::class, 'The order total is too large to use a coupon.');
});

// ---------- Reserve, commit, release ----------

it('holds a coupon use for the configured time', function () {
    $variant = buyable(1200);
    makeCoupon(['code' => 'SAVE10']);

    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 2]]), 'order', 1);

    expect($redemption->status)->toBe(RedemptionStatus::Reserved);
    expect($redemption->discount_amount)->toBe('240.00');
    expect($redemption->user_id)->toBe($this->user->id);
    expect((int) now()->diffInMinutes($redemption->expires_at, false))->toBeBetween(14, 15);
});

it('reads the hold time from the config', function () {
    config(['coupons.reservation_minutes' => 30]);
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect((int) now()->diffInMinutes($redemption->expires_at, false))->toBeBetween(29, 30);
});

it('uses 15 minutes when the config value is not valid', function (mixed $value) {
    config(['coupons.reservation_minutes' => $value]);

    expect(CouponService::reservationMinutes())->toBe(15);
})->with([0, -5, null]);

it('creates one use when the same order reserves twice', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $first = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $second = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect($second->id)->toBe($first->id);
    expect(CouponRedemption::count())->toBe(1);
});

it('counts a held use against the total limit', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'LAST', 'usage_limit_total' => 1, 'usage_limit_per_user' => null]);
    $other = User::factory()->create();

    $this->coupons->reserve('LAST', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect(fn () => $this->coupons->reserve('LAST', $other, cartOf([[$variant, 1]]), 'order', 2))
        ->toThrow(CouponException::class, 'This coupon code is not valid.');

    expect(CouponRedemption::count())->toBe(1);
});

it('limits each customer to one use by default', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'ONCE']);

    $this->coupons->reserve('ONCE', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect(fn () => $this->coupons->reserve('ONCE', $this->user, cartOf([[$variant, 1]]), 'order', 2))
        ->toThrow(CouponException::class);

    $other = User::factory()->create();

    expect($this->coupons->reserve('ONCE', $other, cartOf([[$variant, 1]]), 'order', 3)->status)
        ->toBe(RedemptionStatus::Reserved);
});

it('allows many uses per customer when the limit is empty', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'ALWAYS', 'usage_limit_per_user' => null]);

    foreach (range(1, 4) as $order) {
        $this->coupons->reserve('ALWAYS', $this->user, cartOf([[$variant, 1]]), 'order', $order);
    }

    expect(CouponRedemption::count())->toBe(4);
});

it('allows only one coupon per order', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'FIRST']);
    makeCoupon(['code' => 'SECOND']);

    $this->coupons->reserve('FIRST', $this->user, cartOf([[$variant, 1]]), 'order', 5);

    expect(fn () => $this->coupons->reserve('SECOND', $this->user, cartOf([[$variant, 1]]), 'order', 5))
        ->toThrow(CouponException::class, 'This order already has a coupon.');
});

it('checks the rules again when the order reserves the coupon', function () {
    $variant = buyable(1000);
    $coupon = makeCoupon(['code' => 'SAVE10']);
    $this->coupons->quote('SAVE10', cartOf([[$variant, 1]]), $this->user);

    $coupon->update(['is_active' => false]);

    expect(fn () => $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1))
        ->toThrow(CouponException::class);
});

it('turns a held use into a counted use on commit', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    $committed = $this->coupons->commit($redemption);

    expect($committed->status)->toBe(RedemptionStatus::Redeemed);
    expect($committed->expires_at)->toBeNull();
});

it('commits only once', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    $first = $this->coupons->commit($redemption);
    $second = $this->coupons->commit($redemption);

    expect($second->id)->toBe($first->id);
    expect($second->status)->toBe(RedemptionStatus::Redeemed);
});

it('refuses to commit a use that was released', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $this->coupons->release($redemption);

    expect(fn () => $this->coupons->commit($redemption))
        ->toThrow(CouponException::class, 'This coupon use has been released or has expired.');
});

it('frees the use when it is released', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'LAST', 'usage_limit_total' => 1, 'usage_limit_per_user' => null]);
    $redemption = $this->coupons->reserve('LAST', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect($this->coupons->release($redemption))->toBeTrue();
    expect($redemption->fresh()->status)->toBe(RedemptionStatus::Released);

    $other = User::factory()->create();

    expect($this->coupons->reserve('LAST', $other, cartOf([[$variant, 1]]), 'order', 2)->status)
        ->toBe(RedemptionStatus::Reserved);
});

it('releases only once', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    $this->coupons->release($redemption);

    expect($this->coupons->release($redemption))->toBeFalse();
});

it('lets the same order use the coupon again after its use was released', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $first = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $this->coupons->release($first);

    $again = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect($again->id)->toBe($first->id);
    expect($again->status)->toBe(RedemptionStatus::Reserved);
    expect(CouponRedemption::count())->toBe(1);
});

// ---------- Cash on delivery ----------

it('counts a cash on delivery use at once, with no expiry', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);

    $redemption = $this->coupons->redeem('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect($redemption->status)->toBe(RedemptionStatus::Redeemed);
    expect($redemption->expires_at)->toBeNull();
});

it('gives the use back when the order is cancelled', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'ONCE']);
    $this->coupons->redeem('ONCE', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    expect($this->coupons->releaseForOrder('order', 1))->toBe(1);

    expect($this->coupons->reserve('ONCE', $this->user, cartOf([[$variant, 1]]), 'order', 2)->status)
        ->toBe(RedemptionStatus::Reserved);
});

it('gives back the use of an order only once', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $this->coupons->redeem('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    $this->coupons->releaseForOrder('order', 1);

    expect($this->coupons->releaseForOrder('order', 1))->toBe(0);
});

it('does not touch the coupon uses of other orders', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'ALWAYS', 'usage_limit_per_user' => null]);
    $this->coupons->redeem('ALWAYS', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $other = $this->coupons->redeem('ALWAYS', $this->user, cartOf([[$variant, 1]]), 'order', 2);

    $this->coupons->releaseForOrder('order', 1);

    expect($other->fresh()->status)->toBe(RedemptionStatus::Redeemed);
});

// ---------- Expiry ----------

it('releases only the holds that have expired', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'ALWAYS', 'usage_limit_per_user' => null]);
    $old = $this->coupons->reserve('ALWAYS', $this->user, cartOf([[$variant, 1]]), 'order', 1);

    $this->travel(10)->minutes();
    $newer = $this->coupons->reserve('ALWAYS', $this->user, cartOf([[$variant, 1]]), 'order', 2);

    $this->travel(6)->minutes(); // the first hold is now 16 minutes old

    expect($this->coupons->releaseExpired())->toBe(1);
    expect($old->fresh()->status)->toBe(RedemptionStatus::Expired);
    expect($newer->fresh()->status)->toBe(RedemptionStatus::Reserved);
});

it('never expires a use that was already counted', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $redemption = $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $this->coupons->commit($redemption);

    $this->travel(60)->minutes();

    expect($this->coupons->releaseExpired())->toBe(0);
    expect($redemption->fresh()->status)->toBe(RedemptionStatus::Redeemed);
});

it('runs the expiry command', function () {
    $variant = buyable(1000);
    makeCoupon(['code' => 'SAVE10']);
    $this->coupons->reserve('SAVE10', $this->user, cartOf([[$variant, 1]]), 'order', 1);
    $this->travel(16)->minutes();

    $this->artisan('coupons:release-expired')
        ->expectsOutput('Released 1 expired coupon use(s).')
        ->assertSuccessful();
});
