<?php

use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function scopedPayload(array $overrides = []): array
{
    return array_merge(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10], $overrides);
}

describe('when admin', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->admin()->create());
    });

    // ---------- Create ----------

    it('creates a global coupon when no scope is sent', function () {
        $this->postJson('/api/coupons', scopedPayload())
            ->assertCreated()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.brands', [])
            ->assertJsonPath('data.products', []);
    });

    it('creates a brand coupon', function () {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => [$a->id, $b->id]]))
            ->assertCreated()
            ->assertJsonPath('data.scope', 'brands')
            ->assertJsonPath('data.brands_count', 2)
            ->assertJsonCount(2, 'data.brands')
            ->assertJsonPath('data.products', []);

        expect(DB::table('coupon_brand')->count())->toBe(2);
    });

    it('creates a product coupon, also for a draft product', function () {
        $live = Product::factory()->create();
        $draft = Product::factory()->draft()->create();

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'products', 'product_ids' => [$live->id, $draft->id]]))
            ->assertCreated()
            ->assertJsonPath('data.scope', 'products')
            ->assertJsonPath('data.products_count', 2)
            ->assertJsonCount(2, 'data.products');
    });

    it('requires brands for a brand coupon and products for a product coupon', function () {
        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_ids');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_ids');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'products']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');
    });

    it('rejects brands or products that do not match the scope', function () {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/coupons', scopedPayload(['brand_ids' => [$brand->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'all', 'product_ids' => [$product->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => [$brand->id], 'product_ids' => [$product->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');

        expect(Coupon::count())->toBe(0);
    });

    it('rejects an unknown scope', function () {
        $this->postJson('/api/coupons', scopedPayload(['scope' => 'banana']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    });

    it('rejects unknown, deleted and repeated targets', function () {
        $brand = Brand::factory()->create();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => [999]]))
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids.0');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'products', 'product_ids' => [$deleted->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('product_ids.0');

        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => [$brand->id, $brand->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids.0');
    });

    it('rejects more than 100 targets', function () {
        $this->postJson('/api/coupons', scopedPayload(['scope' => 'brands', 'brand_ids' => range(1, 101)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_ids');
    });

    // ---------- Update ----------

    it('changes a global coupon to a brand coupon only when brands are sent', function () {
        $coupon = makeCoupon();
        $brand = Brand::factory()->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['scope' => 'brands'])
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids');

        $this->putJson("/api/coupons/{$coupon->id}", ['scope' => 'brands', 'brand_ids' => [$brand->id]])
            ->assertOk()
            ->assertJsonPath('data.scope', 'brands')
            ->assertJsonPath('data.brands.0.id', $brand->id);
    });

    it('clears the brands when a coupon becomes global', function () {
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['scope' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.brands', []);

        expect(DB::table('coupon_brand')->count())->toBe(0);
    });

    it('switches from brands to products and clears the brands', function () {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create();
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['scope' => 'products', 'product_ids' => [$product->id]])
            ->assertOk()
            ->assertJsonPath('data.scope', 'products')
            ->assertJsonPath('data.brands', [])
            ->assertJsonPath('data.products.0.id', $product->id);

        expect(DB::table('coupon_brand')->count())->toBe(0);
        expect(DB::table('coupon_product')->count())->toBe(1);
    });

    it('replaces the list of brands', function () {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();
        $coupon = Coupon::factory()->forBrands([$a])->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['brand_ids' => [$b->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.brands')
            ->assertJsonPath('data.brands.0.id', $b->id);
    });

    it('keeps the targets when other fields are updated', function () {
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['value' => 15])
            ->assertOk()
            ->assertJsonPath('data.scope', 'brands')
            ->assertJsonPath('data.brands.0.id', $brand->id);
    });

    it('rejects brands on a product coupon and products on a global coupon', function () {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create();
        $productCoupon = Coupon::factory()->forProducts([$product])->create();
        $global = makeCoupon(['code' => 'GLOBAL01']);

        $this->putJson("/api/coupons/{$productCoupon->id}", ['brand_ids' => [$brand->id]])
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids');

        $this->putJson("/api/coupons/{$global->id}", ['product_ids' => [$product->id]])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');
    });

    it('does not allow an empty list for a brand coupon', function () {
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->putJson("/api/coupons/{$coupon->id}", ['brand_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('brand_ids');
    });

    // ---------- Show and list ----------

    it('shows the targets of a coupon', function () {
        $brand = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->getJson("/api/coupons/{$coupon->id}")
            ->assertOk()
            ->assertJsonPath('data.scope', 'brands')
            ->assertJsonPath('data.brands.0.slug', 'rfl');
    });

    it('shows the number of targets in the list and filters by scope', function () {
        Coupon::factory()->forBrands([Brand::factory()->create(), Brand::factory()->create()])->create(['code' => 'BRANDS01']);
        Coupon::factory()->forProducts([Product::factory()->create()])->create(['code' => 'PRODUCT1']);
        makeCoupon(['code' => 'GLOBAL01']);

        $this->getJson('/api/coupons?scope=brands')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BRANDS01')
            ->assertJsonPath('data.0.brands_count', 2);

        $this->getJson('/api/coupons?scope=banana')->assertStatus(422)->assertJsonValidationErrors('scope');
    });

    // ---------- Brand delete ----------

    it('blocks deleting a brand that is used by a coupon, also a deleted one', function () {
        $brand = Brand::factory()->create();
        $coupon = Coupon::factory()->forBrands([$brand])->create();

        $this->deleteJson("/api/brands/{$brand->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This brand is used by one or more coupons. Remove it from those coupons first.');

        $coupon->delete();

        $this->deleteJson("/api/brands/{$brand->id}")->assertStatus(409);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    });
});

// ---------- Quote endpoint ----------

describe('quote', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('shows which lines the coupon covers', function () {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();
        $itemA = buyableForBrand($a, 1000);
        $itemB = buyableForBrand($b, 1000);
        Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

        $this->postJson('/api/coupons/quote', ['code' => 'BRANDA', 'items' => cartOf([[$itemA, 1], [$itemB, 1]])])
            ->assertOk()
            ->assertJsonPath('data.scope', 'brands')
            ->assertJsonPath('data.items_total', '2000.00')
            ->assertJsonPath('data.eligible_total', '1000.00')
            ->assertJsonPath('data.discount', '100.00')
            ->assertJsonPath('data.lines.0.eligible', true)
            ->assertJsonPath('data.lines.0.discount', '100.00')
            ->assertJsonPath('data.lines.1.eligible', false)
            ->assertJsonPath('data.lines.1.discount', '0.00');
    });

    it('explains when the coupon does not apply to the cart', function () {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();
        Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

        $this->postJson('/api/coupons/quote', ['code' => 'BRANDA', 'items' => cartOf([[buyableForBrand($b, 1000), 1]])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This coupon does not apply to the items in your cart.')
            ->assertJsonPath('data', null);
    });
});
