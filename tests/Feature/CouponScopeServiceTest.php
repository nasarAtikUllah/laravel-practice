<?php

use App\Exceptions\CouponException;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Coupon;
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

// ---------- Brand coupons ----------

it('discounts only the items of the selected brand', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    $itemA = buyableForBrand($a, 1000);
    $itemB = buyableForBrand($b, 1000);
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    $quote = $this->coupons->quote('BRANDA', cartOf([[$itemA, 1], [$itemB, 1]]), $this->user);

    expect($quote['scope'])->toBe('brands');
    expect($quote['items_total'])->toBe('2000.00');
    expect($quote['eligible_total'])->toBe('1000.00');
    expect($quote['discount'])->toBe('100.00');
    expect($quote['total_after_discount'])->toBe('1900.00');
    expect(array_column($quote['lines'], 'eligible'))->toBe([true, false]);
    expect(array_column($quote['lines'], 'discount'))->toBe(['100.00', '0.00']);
    expect(array_column($quote['lines'], 'line_payable'))->toBe(['900.00', '1000.00']);
});

it('covers every selected brand', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    $c = Brand::factory()->create();
    Coupon::factory()->forBrands([$a, $b])->create(['code' => 'TWOBRANDS']);

    $quote = $this->coupons->quote('TWOBRANDS', cartOf([
        [buyableForBrand($a, 1000), 1],
        [buyableForBrand($b, 500), 1],
        [buyableForBrand($c, 2000), 1],
    ]), $this->user);

    expect($quote['eligible_total'])->toBe('1500.00');
    expect($quote['discount'])->toBe('150.00');
    expect(array_column($quote['lines'], 'eligible'))->toBe([true, true, false]);
});

it('refuses a brand coupon when the cart has no item of that brand', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    expect(fn () => $this->coupons->quote('BRANDA', cartOf([[buyableForBrand($b, 1000), 1]]), $this->user))
        ->toThrow(CouponException::class, 'This coupon does not apply to the items in your cart.');
});

it('follows the current brand of a product', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    $item = buyableForBrand($a, 1000);
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    expect($this->coupons->quote('BRANDA', cartOf([[$item, 1]]), $this->user)['discount'])->toBe('100.00');

    $newCollection = Collection::factory()->create(['brand_id' => $b->id]);
    $item->product->update(['brand_id' => $b->id, 'collection_id' => $newCollection->id]);

    expect(fn () => $this->coupons->quote('BRANDA', cartOf([[$item, 1]]), $this->user))
        ->toThrow(CouponException::class, 'This coupon does not apply to the items in your cart.');
});

// ---------- Product coupons ----------

it('discounts only the selected product, not other products of the same brand', function () {
    $brand = Brand::factory()->create();
    $target = buyableForBrand($brand, 1000);
    $other = buyableForBrand($brand, 1000);
    Coupon::factory()->forProducts([$target->product_id])->create(['code' => 'ONEPRODUCT']);

    $quote = $this->coupons->quote('ONEPRODUCT', cartOf([[$target, 1], [$other, 1]]), $this->user);

    expect(array_column($quote['lines'], 'eligible'))->toBe([true, false]);
    expect($quote['discount'])->toBe('100.00');
});

it('covers every variant of the selected product', function () {
    $product = makeProduct([], 0);
    $v1 = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 100, 'compare_price' => null, 'stock' => 5]);
    $v2 = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 200, 'compare_price' => null, 'stock' => 5]);
    Coupon::factory()->forProducts([$product->id])->create(['code' => 'ALLVARIANTS']);

    $quote = $this->coupons->quote('ALLVARIANTS', cartOf([[$v1, 1], [$v2, 1]]), $this->user);

    expect(array_column($quote['lines'], 'eligible'))->toBe([true, true]);
    expect($quote['discount'])->toBe('30.00');
});

it('refuses a product coupon when the product is not in the cart', function () {
    $target = buyable(1000);
    $other = buyable(1000);
    Coupon::factory()->forProducts([$target->product_id])->create(['code' => 'ONEPRODUCT']);

    expect(fn () => $this->coupons->quote('ONEPRODUCT', cartOf([[$other, 1]]), $this->user))
        ->toThrow(CouponException::class, 'This coupon does not apply to the items in your cart.');
});

it('makes only the selected product free with a 100 percent coupon', function () {
    $target = buyable(1000);
    $other = buyable(500);
    Coupon::factory()->forProducts([$target->product_id])->percentage(100)->create(['code' => 'FREEONE']);

    $quote = $this->coupons->quote('FREEONE', cartOf([[$target, 1], [$other, 1]]), $this->user);

    expect($quote['discount'])->toBe('1000.00');
    expect($quote['total_after_discount'])->toBe('500.00');
});

// ---------- Amounts on the covered items ----------

it('caps a fixed coupon at the covered subtotal', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->fixed(500)->forBrands([$a])->create(['code' => 'FIX500']);

    $quote = $this->coupons->quote('FIX500', cartOf([
        [buyableForBrand($a, 300), 1],
        [buyableForBrand($b, 1000), 1],
    ]), $this->user);

    expect($quote['discount'])->toBe('300.00');
    expect($quote['total_after_discount'])->toBe('1000.00');
});

it('gives the full fixed amount when the covered items are worth more', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->fixed(200)->forBrands([$a])->create(['code' => 'FIX200']);

    $quote = $this->coupons->quote('FIX200', cartOf([
        [buyableForBrand($a, 300), 1],
        [buyableForBrand($b, 1000), 1],
    ]), $this->user);

    expect($quote['discount'])->toBe('200.00');
});

it('caps a percentage discount at the maximum discount of the covered items', function () {
    $a = Brand::factory()->create();
    Coupon::factory()->forBrands([$a])->create(['code' => 'HALF', 'value' => 50, 'max_discount' => 100]);

    $quote = $this->coupons->quote('HALF', cartOf([[buyableForBrand($a, 1000), 1]]), $this->user);

    expect($quote['discount'])->toBe('100.00');
});

it('measures the minimum on the covered items only', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->forBrands([$a])->create(['code' => 'BIGONLY', 'min_order_amount' => 500]);

    expect(fn () => $this->coupons->quote('BIGONLY', cartOf([
        [buyableForBrand($a, 400), 1],
        [buyableForBrand($b, 1000), 1],
    ]), $this->user))->toThrow(
        CouponException::class,
        'The total of the items this coupon applies to must be at least 500.00 to use this coupon.'
    );

    $quote = $this->coupons->quote('BIGONLY', cartOf([
        [buyableForBrand($a, 500), 1],
        [buyableForBrand($b, 1000), 1],
    ]), $this->user);

    expect($quote['discount'])->toBe('50.00');
});

it('splits the discount over the covered lines only', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->fixed(1)->forBrands([$a])->create(['code' => 'ONEBUCK']);

    $quote = $this->coupons->quote('ONEBUCK', cartOf([
        [buyableForBrand($b, 10), 1],
        [buyableForBrand($a, 10), 1],
        [buyableForBrand($a, 10), 1],
        [buyableForBrand($a, 10), 1],
    ]), $this->user);

    expect(array_column($quote['lines'], 'discount'))->toBe(['0.00', '0.33', '0.33', '0.34']);
    expect($quote['discount'])->toBe('1.00');
});

it('marks every line as covered for a global coupon', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    makeCoupon(['code' => 'EVERYTHING']);

    $quote = $this->coupons->quote('EVERYTHING', cartOf([
        [buyableForBrand($a, 1000), 1],
        [buyableForBrand($b, 1000), 1],
    ]), $this->user);

    expect($quote['scope'])->toBe('all');
    expect(array_column($quote['lines'], 'eligible'))->toBe([true, true]);
    expect($quote['eligible_total'])->toBe($quote['items_total']);
});

// ---------- Using the coupon at checkout ----------

it('stores the discount of the covered items only when a use is held', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    $items = cartOf([[buyableForBrand($a, 1000), 1], [buyableForBrand($b, 1000), 1]]);
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    $redemption = $this->coupons->reserve('BRANDA', $this->user, $items, 'order', 1);

    expect($redemption->discount_amount)->toBe('100.00');
});

it('refuses to hold a use when no item is covered and writes nothing', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    expect(fn () => $this->coupons->reserve('BRANDA', $this->user, cartOf([[buyableForBrand($b, 1000), 1]]), 'order', 1))
        ->toThrow(CouponException::class);

    expect(CouponRedemption::count())->toBe(0);
});
