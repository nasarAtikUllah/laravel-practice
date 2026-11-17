<?php

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingOption;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

function actingAsAdmin(): User
{
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user);

    return $user;
}

function makeProduct(array $attributes = [], int $variants = 1): Product
{
    if (isset($attributes['collection_id']) && ! isset($attributes['brand_id'])) {
        $attributes['brand_id'] = Collection::find($attributes['collection_id'])->brand_id;
    }

    $product = Product::factory()->create($attributes);

    if ($variants > 0) {
        ProductVariant::factory()->count($variants)->create(['product_id' => $product->id]);
    }

    return $product;
}

function productPayload(Collection $collection, array $overrides = []): array
{
    return array_merge([
        'brand_id' => $collection->brand_id,
        'collection_id' => $collection->id,
        'name' => 'RFL Non-Stick Pan',
    ], $overrides);
}

function variantPayload(array $overrides = []): array
{
    return array_merge([
        'title' => '24 cm',
        'sku' => 'PAN-24',
        'price' => 1200,
        'compare_price' => 1500,
        'stock' => 10,
    ], $overrides);
}

function fakePhoto(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 100, 100);
}

function makeCoupon(array $attributes = []): Coupon
{
    return Coupon::factory()->create($attributes);
}

/** A variant that can be bought: its product is published and its brand and collection are active. */
function buyable(float $price = 1000, array $attributes = []): ProductVariant
{
    $product = makeProduct([], 0);

    return ProductVariant::factory()->create(array_merge([
        'product_id' => $product->id,
        'price' => $price,
        'compare_price' => null,
        'stock' => 10,
    ], $attributes));
}

/** @param array<int, array{0: ProductVariant, 1: int}> $pairs */
function cartOf(array $pairs): array
{
    return array_map(fn (array $pair) => ['variant_id' => $pair[0]->id, 'quantity' => $pair[1]], $pairs);
}

function makeRedemption(Coupon $coupon, ?User $user = null, array $attributes = []): CouponRedemption
{
    return CouponRedemption::create(array_merge([
        'coupon_id' => $coupon->id,
        'user_id' => ($user ?? User::factory()->create())->id,
        'reference_type' => 'order',
        'reference_id' => fake()->unique()->numberBetween(1, 1000000),
        'discount_amount' => 100,
        'status' => 'redeemed',
    ], $attributes));
}

/** A buyable variant whose product belongs to the given brand. */
function buyableForBrand(Brand $brand, float $price = 1000): ProductVariant
{
    $collection = Collection::factory()->create(['brand_id' => $brand->id]);
    $product = makeProduct(['collection_id' => $collection->id], 0);

    return ProductVariant::factory()->create([
        'product_id' => $product->id,
        'price' => $price,
        'compare_price' => null,
        'stock' => 10,
        'reserved' => 0,
    ]);
}
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function makeProductWithoutStock(array $attributes = [], int $variants = 1): Product
{
    $product = makeProduct($attributes, 0);

    ProductVariant::factory()->count($variants)->create(['product_id' => $product->id, 'stock' => 0, 'reserved' => 0]);

    return $product;
}

/** The three delivery options, as the seeder creates them. */
function seedShipping(): array
{
    return [
        'inside' => ShippingOption::factory()->asDefault()->create(['code' => 'inside-city', 'name' => 'Inside city delivery', 'fee' => 60, 'sort_order' => 10]),
        'outside' => ShippingOption::factory()->create(['code' => 'outside-city', 'name' => 'Outside city delivery', 'fee' => 120, 'sort_order' => 20]),
        'free' => ShippingOption::factory()->freeFrom(5000)->create(['code' => 'free-delivery', 'name' => 'Free delivery', 'sort_order' => 30]),
    ];
}

/** The delivery details a customer types at checkout. */
function checkoutInput(array $overrides = []): array
{
    return array_merge([
        'payment_method' => 'cod',
        'shipping_name' => 'Rahim Uddin',
        'shipping_phone' => '01712345678',
        'shipping_address' => 'House 12, Road 5, Dhanmondi',
        'shipping_district' => 'Dhaka',
        'note' => null,
    ], $overrides);
}

/** Fills the cart, optionally applies a coupon, and returns the hash that checkout needs. */
function readyCart(User $user, array $pairs, ?string $coupon = null): string
{
    $carts = app(CartService::class);

    foreach ($pairs as [$variant, $quantity]) {
        $carts->addItem($user, $variant->id, $quantity);
    }

    if ($coupon !== null) {
        $carts->applyCoupon($user, $coupon);
    }

    return $carts->prepare($user)['cart_hash'];
}

function placeOrder(User $user, string $hash, array $overrides = [], ?string $key = null): Order
{
    return app(OrderService::class)->checkout(
        $user,
        checkoutInput(['cart_hash' => $hash] + $overrides),
        $key ?? 'key-'.Str::random(12)
    );
}
