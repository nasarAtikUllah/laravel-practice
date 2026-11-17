<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ---------- Access and response ----------

it('lets a guest list the active coupons in BDT', function () {
    Coupon::factory()->create(['code' => 'SAVE10']);

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonPath('message', 'Coupons retrieved successfully.')
        ->assertJsonPath('currency', 'BDT')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'SAVE10');
});

it('works the same for a logged in customer', function () {
    Sanctum::actingAs(User::factory()->create());
    Coupon::factory()->create(['code' => 'SAVE10']);

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns an empty list when no coupon is active', function () {
    Coupon::factory()->inactive()->create();

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('data', []);
});

it('shows the details of a percentage coupon', function () {
    Coupon::factory()->percentage(10)->create([
        'code' => 'SAVE10',
        'max_discount' => 200,
        'min_order_amount' => 1000,
        'expires_at' => now()->addDays(5),
    ]);

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'SAVE10')
        ->assertJsonPath('data.0.type', 'percentage')
        ->assertJsonPath('data.0.scope', 'all')
        ->assertJsonPath('data.0.value', '10.00')
        ->assertJsonPath('data.0.max_discount', '200.00')
        ->assertJsonPath('data.0.min_order_amount', '1000.00')
        ->assertJsonPath('data.0.starts_at', null)
        ->assertJsonStructure(['data' => [['expires_at']]]);
});

it('shows the details of a fixed coupon', function () {
    Coupon::factory()->fixed(150)->create(['code' => 'TAKA150']);

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'fixed')
        ->assertJsonPath('data.0.value', '150.00')
        ->assertJsonPath('data.0.max_discount', null)
        ->assertJsonPath('data.0.min_order_amount', null);
});

it('shows the code in capital letters', function () {
    Coupon::factory()->create(['code' => ' save10 ']);

    $this->getJson('/api/frontend/coupons')->assertJsonPath('data.0.code', 'SAVE10');
});

it('does not show private fields of a coupon', function () {
    Coupon::factory()->create(['usage_limit_total' => 100, 'usage_limit_per_user' => 1]);

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.state')
        ->assertJsonMissingPath('data.0.usage_limit_total')
        ->assertJsonMissingPath('data.0.usage_limit_per_user')
        ->assertJsonMissingPath('data.0.used_count')
        ->assertJsonMissingPath('data.0.created_at')
        ->assertJsonMissingPath('data.0.updated_at');
});

it('lists the newest coupon first', function () {
    Coupon::factory()->create(['code' => 'OLDER']);
    Coupon::factory()->create(['code' => 'NEWER']);

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['NEWER', 'OLDER']);
});

// ---------- Which coupons are listed ----------

it('hides a coupon that was turned off', function () {
    Coupon::factory()->create(['code' => 'ON']);
    Coupon::factory()->inactive()->create(['code' => 'OFF']);

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['ON']);
});

it('hides an expired coupon', function () {
    Coupon::factory()->create(['code' => 'LIVE']);
    Coupon::factory()->expired()->create(['code' => 'OLD']);

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['LIVE']);
});

it('hides a coupon that has not started yet', function () {
    Coupon::factory()->create(['code' => 'LIVE']);
    Coupon::factory()->scheduled()->create(['code' => 'SOON']);

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['LIVE']);
});

it('hides a deleted coupon', function () {
    Coupon::factory()->create(['code' => 'LIVE']);
    Coupon::factory()->create(['code' => 'GONE'])->delete();

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['LIVE']);
});

it('shows a coupon without dates and a coupon inside its dates', function () {
    Coupon::factory()->create(['code' => 'NODATES']);
    Coupon::factory()->create(['code' => 'INSIDE', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
    Coupon::factory()->create(['code' => 'NOEND', 'starts_at' => now()->subDay(), 'expires_at' => null]);
    Coupon::factory()->create(['code' => 'NOSTART', 'starts_at' => null, 'expires_at' => now()->addDay()]);

    $this->getJson('/api/frontend/coupons')->assertJsonCount(4, 'data');
});

it('includes the start time and excludes the expiry time', function () {
    $this->freezeTime();

    Coupon::factory()->create(['code' => 'STARTS-NOW', 'starts_at' => now(), 'expires_at' => now()->addDay()]);
    Coupon::factory()->create(['code' => 'ENDS-NOW', 'starts_at' => now()->subDay(), 'expires_at' => now()]);

    $codes = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['STARTS-NOW']);
});

it('follows the same rule as the admin active state', function () {
    $live = Coupon::factory()->create(['code' => 'LIVE']);
    $off = Coupon::factory()->inactive()->create(['code' => 'OFF']);
    $old = Coupon::factory()->expired()->create(['code' => 'OLD']);
    $soon = Coupon::factory()->scheduled()->create(['code' => 'SOON']);

    $listed = collect($this->getJson('/api/frontend/coupons')->json('data'))->pluck('code')->all();

    expect($live->state())->toBe('active');
    expect($off->state())->not->toBe('active');
    expect($old->state())->not->toBe('active');
    expect($soon->state())->not->toBe('active');
    expect($listed)->toBe(['LIVE']);
});

// ---------- Brand and product coupons ----------

it('shows no brands or products for a coupon on everything', function () {
    Coupon::factory()->create();

    $this->getJson('/api/frontend/coupons')
        ->assertJsonPath('data.0.scope', 'all')
        ->assertJsonMissingPath('data.0.brands')
        ->assertJsonMissingPath('data.0.products');
});

it('shows the active brands of a brand coupon', function () {
    $active = Brand::factory()->create(['name' => 'Walton', 'slug' => 'walton']);
    $inactive = Brand::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden']);
    Coupon::factory()->forBrands([$active, $inactive])->create();

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonPath('data.0.scope', 'brands')
        ->assertJsonCount(1, 'data.0.brands')
        ->assertJsonPath('data.0.brands.0.id', $active->id)
        ->assertJsonPath('data.0.brands.0.name', 'Walton')
        ->assertJsonPath('data.0.brands.0.slug', 'walton')
        ->assertJsonMissingPath('data.0.products');
});

it('shows only the products customers can see for a product coupon', function () {
    $visible = makeProduct(['name' => 'Steel Pan', 'slug' => 'steel-pan'], 0);
    $draft = makeProduct(['name' => 'Secret Draft', 'slug' => 'secret-draft', 'status' => ProductStatus::Draft], 0);
    $hiddenBrand = makeProduct(['name' => 'Brand Off', 'slug' => 'brand-off'], 0);
    $hiddenBrand->brand->update(['is_active' => false]);

    Coupon::factory()->forProducts([$visible, $draft, $hiddenBrand])->create();

    $response = $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonPath('data.0.scope', 'products')
        ->assertJsonCount(1, 'data.0.products')
        ->assertJsonPath('data.0.products.0.id', $visible->id)
        ->assertJsonPath('data.0.products.0.slug', 'steel-pan')
        ->assertJsonMissingPath('data.0.brands');

    expect($response->getContent())->not->toContain('Secret Draft');
    expect($response->getContent())->not->toContain('Brand Off');
});

it('shows an empty list for a brand coupon whose brands are all hidden', function () {
    $inactive = Brand::factory()->inactive()->create();
    Coupon::factory()->forBrands([$inactive])->create();

    $this->getJson('/api/frontend/coupons')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(0, 'data.0.brands');
});

// ---------- Performance ----------

it('uses the same number of queries however many coupons are listed', function () {
    Coupon::factory()->forBrands([Brand::factory()->create()])->create();
    Coupon::factory()->forProducts([makeProduct([], 0)])->create();

    DB::enableQueryLog();
    $this->getJson('/api/frontend/coupons')->assertOk();
    $small = count(DB::getQueryLog());

    foreach (range(1, 4) as $i) {
        Coupon::factory()->forBrands([Brand::factory()->create()])->create();
        Coupon::factory()->forProducts([makeProduct([], 0)])->create();
    }

    DB::flushQueryLog();
    $this->getJson('/api/frontend/coupons')->assertOk();
    $large = count(DB::getQueryLog());

    expect($large)->toBe($small);
});
