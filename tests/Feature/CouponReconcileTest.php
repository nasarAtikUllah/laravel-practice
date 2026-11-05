<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports no problems when everything is fine', function () {
    $coupon = makeCoupon(['usage_limit_total' => 5, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'reserved', 'expires_at' => now()->addMinutes(10)]);
    makeRedemption($coupon, null, ['status' => 'released']);

    $this->artisan('coupons:reconcile')
        ->expectsOutput('No problems found.')
        ->assertSuccessful();
});

it('finds a hold that should have expired more than 5 minutes ago', function () {
    $coupon = makeCoupon(['usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'reserved', 'expires_at' => now()->subMinutes(6)]);

    $this->artisan('coupons:reconcile')
        ->expectsOutputToContain('1 held coupon use(s) expired more than 5 minutes ago')
        ->assertFailed();
});

it('does not warn about a hold that expired only a moment ago', function () {
    $coupon = makeCoupon(['usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'reserved', 'expires_at' => now()->subMinutes(4)]);

    $this->artisan('coupons:reconcile')->assertSuccessful();
});

it('finds a hold without an expiry time', function () {
    $coupon = makeCoupon(['usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'reserved', 'expires_at' => null]);

    $this->artisan('coupons:reconcile')
        ->expectsOutputToContain('1 held coupon use(s) have no expiry time')
        ->assertFailed();
});

it('finds a coupon that was used more than its total limit', function () {
    $coupon = makeCoupon(['usage_limit_total' => 1, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'redeemed']);

    $this->artisan('coupons:reconcile')
        ->expectsOutputToContain('1 coupon(s) were used more times than their total limit.')
        ->assertFailed();
});

it('does not count released or expired uses against the total limit', function () {
    $coupon = makeCoupon(['usage_limit_total' => 1, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'released']);
    makeRedemption($coupon, null, ['status' => 'expired']);

    $this->artisan('coupons:reconcile')->assertSuccessful();
});

it('accepts a coupon that is exactly at its limit', function () {
    $coupon = makeCoupon(['usage_limit_total' => 2, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'reserved', 'expires_at' => now()->addMinutes(5)]);

    $this->artisan('coupons:reconcile')->assertSuccessful();
});

it('checks deleted coupons too', function () {
    $coupon = makeCoupon(['usage_limit_total' => 1, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    $coupon->delete();

    $this->artisan('coupons:reconcile')->assertFailed();
});

it('ignores a coupon that has no total limit', function () {
    $coupon = makeCoupon(['usage_limit_total' => null, 'usage_limit_per_user' => null]);
    makeRedemption($coupon, null, ['status' => 'redeemed']);
    makeRedemption($coupon, null, ['status' => 'redeemed']);

    $this->artisan('coupons:reconcile')->assertSuccessful();
});
