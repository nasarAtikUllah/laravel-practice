<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function priceProduct(string $name, float $price, array $attributes = []): Product
{
    $product = makeProduct(array_merge(['name' => $name, 'slug' => Str::slug($name)], $attributes), 0);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'price' => $price,
        'compare_price' => null,
        'stock' => 5,
    ]);

    return $product;
}

// ---------- LIST ----------

it('lets guests list products without a token', function () {
    priceProduct('Steel Pan', 1000);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonPath('message', 'Products retrieved successfully.')
        ->assertJsonCount(1, 'data');
});

it('shows published and upcoming products', function () {
    priceProduct('Steel Pan', 1000);
    priceProduct('Soon Pot', 900, ['status' => ProductStatus::Upcoming]);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('hides draft and archived products', function () {
    priceProduct('Visible', 1000);
    priceProduct('Draft One', 1000, ['status' => ProductStatus::Draft]);
    priceProduct('Archived One', 1000, ['status' => ProductStatus::Archived]);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'visible');
});

it('hides products of an inactive brand', function () {
    $brand = Brand::factory()->inactive()->create();
    $collection = Collection::factory()->create(['brand_id' => $brand->id]);
    priceProduct('Hidden', 1000, ['collection_id' => $collection->id]);

    $this->getJson('/api/frontend/products')->assertOk()->assertJsonCount(0, 'data');
});

it('hides products of an inactive collection', function () {
    $collection = Collection::factory()->create(['is_active' => false]);
    priceProduct('Hidden', 1000, ['collection_id' => $collection->id]);

    $this->getJson('/api/frontend/products')->assertOk()->assertJsonCount(0, 'data');
});

it('hides soft-deleted products', function () {
    priceProduct('Gone', 1000)->delete();

    $this->getJson('/api/frontend/products')->assertOk()->assertJsonCount(0, 'data');
});

it('shows the lowest variant price as price_from', function () {
    $product = makeProduct(['name' => 'Pan', 'slug' => 'pan'], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1500]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1200]);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonPath('data.0.price_from', '1200.00');
});

it('shows null price_from and out of stock for a product without variants', function () {
    makeProduct([], 0);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonPath('data.0.price_from', null)
        ->assertJsonPath('data.0.in_stock', false);
});

it('is in stock when any variant has stock', function () {
    $product = makeProduct([], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 0]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 3]);

    $this->getJson('/api/frontend/products')->assertJsonPath('data.0.in_stock', true);
});

it('is out of stock when all variants have zero stock', function () {
    $product = makeProduct([], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 0]);

    $this->getJson('/api/frontend/products')->assertJsonPath('data.0.in_stock', false);
});

it('does not expose exact stock or private fields in the list', function () {
    priceProduct('Steel Pan', 1000);

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonMissingPath('data.0.total_stock')
        ->assertJsonMissingPath('data.0.stock')
        ->assertJsonMissingPath('data.0.variants')
        ->assertJsonMissingPath('data.0.description')
        ->assertJsonMissingPath('data.0.brand_id')
        ->assertJsonMissingPath('data.0.created_at');
});

it('searches products by name', function () {
    priceProduct('Steel Pan', 1000);
    priceProduct('Glass Cup', 500);

    $this->getJson('/api/frontend/products?search=pan')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Steel Pan');
});

it('filters products by brand slug', function () {
    $rfl = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);
    $collection = Collection::factory()->create(['brand_id' => $rfl->id]);
    priceProduct('RFL Pan', 1000, ['collection_id' => $collection->id]);
    priceProduct('Other Pan', 1000);

    $this->getJson('/api/frontend/products?brand=rfl')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'rfl-pan');
});

it('filters products by collection slug', function () {
    $collection = Collection::factory()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
    priceProduct('Kitchen Pan', 1000, ['collection_id' => $collection->id]);
    priceProduct('Other Pan', 1000);

    $this->getJson('/api/frontend/products?collection=kitchen')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'kitchen-pan');
});

it('sorts products by price and by name', function () {
    priceProduct('Bravo', 200);
    priceProduct('Charlie', 300);
    priceProduct('Alpha', 100);

    $asc = $this->getJson('/api/frontend/products?sort=price_asc')->json('data.*.price_from');
    $desc = $this->getJson('/api/frontend/products?sort=price_desc')->json('data.*.price_from');
    $name = $this->getJson('/api/frontend/products?sort=name')->json('data.*.name');

    expect($asc)->toBe(['100.00', '200.00', '300.00']);
    expect($desc)->toBe(['300.00', '200.00', '100.00']);
    expect($name)->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('shows the newest product first by default', function () {
    priceProduct('Older', 100);
    priceProduct('Newer', 100);

    $this->getJson('/api/frontend/products')->assertJsonPath('data.0.slug', 'newer');
});

it('paginates 12 products per page', function () {
    Product::factory()->count(15)->create();

    $this->getJson('/api/frontend/products')
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/frontend/products?page=2')->assertJsonCount(3, 'data');
});

it('shows the featured image in the list', function () {
    Storage::fake('public');
    $product = priceProduct('Steel Pan', 1000);
    $product->addMedia(fakePhoto('pan.jpg'))->toMediaCollection('featured_image');

    $response = $this->getJson('/api/frontend/products')->assertOk();

    expect($response->json('data.0.featured_image.url'))->not->toBeNull();
});

it('returns a null featured image when there is none', function () {
    priceProduct('Steel Pan', 1000);

    $this->getJson('/api/frontend/products')->assertJsonPath('data.0.featured_image', null);
});

it('uses the same number of queries however many products are listed', function () {
    Product::factory()->count(2)->create()
        ->each(fn ($p) => ProductVariant::factory()->create(['product_id' => $p->id]));

    DB::enableQueryLog();
    $this->getJson('/api/frontend/products')->assertOk();
    $small = count(DB::getQueryLog());

    Product::factory()->count(8)->create()
        ->each(fn ($p) => ProductVariant::factory()->create(['product_id' => $p->id]));

    DB::flushQueryLog();
    $this->getJson('/api/frontend/products')->assertOk();
    $large = count(DB::getQueryLog());

    expect($large)->toBe($small);
});

// ---------- DETAILS ----------

it('shows a product by slug without a token', function () {
    $product = makeProduct(['name' => 'Steel Pan', 'slug' => 'steel-pan', 'description' => 'Nice pan'], 1);

    $this->getJson('/api/frontend/products/steel-pan')
        ->assertOk()
        ->assertJsonPath('message', 'Product retrieved successfully.')
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'Steel Pan')
        ->assertJsonPath('data.description', 'Nice pan')
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.brand.slug', $product->brand->slug)
        ->assertJsonPath('data.collection.slug', $product->collection->slug)
        ->assertJsonCount(1, 'data.variants');
});

it('sorts the variants by price', function () {
    $product = makeProduct(['slug' => 'pan'], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1500, 'compare_price' => null]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1200, 'compare_price' => null]);

    $prices = $this->getJson('/api/frontend/products/pan')->json('data.variants.*.price');

    expect($prices)->toBe(['1200.00', '1500.00']);
});

it('calculates the discount percent', function () {
    $product = makeProduct(['slug' => 'pan'], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1200, 'compare_price' => 1500]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 1600, 'compare_price' => null]);

    $this->getJson('/api/frontend/products/pan')
        ->assertOk()
        ->assertJsonPath('data.variants.0.discount_percent', 20)
        ->assertJsonPath('data.variants.1.discount_percent', null);
});

it('does not show soft-deleted variants', function () {
    $product = makeProduct(['slug' => 'pan'], 2);
    $product->variants()->first()->delete();

    $this->getJson('/api/frontend/products/pan')->assertJsonCount(1, 'data.variants');
});

it('does not expose exact stock or private fields in the details', function () {
    makeProduct(['slug' => 'pan'], 1);

    $this->getJson('/api/frontend/products/pan')
        ->assertOk()
        ->assertJsonMissingPath('data.variants.0.stock')
        ->assertJsonMissingPath('data.variants.0.product_id')
        ->assertJsonMissingPath('data.brand_id')
        ->assertJsonMissingPath('data.created_at')
        ->assertJsonPath('data.variants.0.in_stock', fn ($value) => is_bool($value));
});

it('marks a published product as purchasable and an upcoming one as not', function () {
    makeProduct(['slug' => 'live'], 1);
    makeProduct(['slug' => 'soon', 'status' => ProductStatus::Upcoming], 1);

    $this->getJson('/api/frontend/products/live')->assertJsonPath('data.is_purchasable', true);
    $this->getJson('/api/frontend/products/soon')->assertJsonPath('data.is_purchasable', false);
});

it('shows in_stock based on the variants', function () {
    $product = makeProduct(['slug' => 'pan'], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 0]);

    $this->getJson('/api/frontend/products/pan')->assertJsonPath('data.in_stock', false);

    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 4]);

    $this->getJson('/api/frontend/products/pan')->assertJsonPath('data.in_stock', true);
});

it('falls back to the product name for the seo title', function () {
    makeProduct(['name' => 'Steel Pan', 'slug' => 'steel-pan', 'seo_title' => null], 1);

    $this->getJson('/api/frontend/products/steel-pan')->assertJsonPath('data.seo.title', 'Steel Pan');
});

it('uses the seo fields when they are set', function () {
    makeProduct([
        'slug' => 'steel-pan',
        'seo_title' => 'Best pan',
        'seo_description' => 'Buy it now',
    ], 1);

    $this->getJson('/api/frontend/products/steel-pan')
        ->assertJsonPath('data.seo.title', 'Best pan')
        ->assertJsonPath('data.seo.description', 'Buy it now');
});

it('shows variant images in the details', function () {
    Storage::fake('public');
    $product = makeProduct(['slug' => 'pan'], 1);
    $product->variants()->first()->addMedia(fakePhoto('v.jpg'))->toMediaCollection('images');

    $this->getJson('/api/frontend/products/pan')->assertJsonCount(1, 'data.variants.0.images');
});

it('returns 404 for a product that is not published or upcoming', function (string $status) {
    makeProduct(['slug' => 'hidden', 'status' => $status], 1);

    $this->getJson('/api/frontend/products/hidden')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
})->with(['draft', 'archived']);

it('returns 404 for a product of an inactive brand', function () {
    $brand = Brand::factory()->inactive()->create();
    $collection = Collection::factory()->create(['brand_id' => $brand->id]);
    makeProduct(['slug' => 'hidden', 'collection_id' => $collection->id], 1);

    $this->getJson('/api/frontend/products/hidden')->assertNotFound();
});

it('returns 404 for a product of an inactive collection', function () {
    $collection = Collection::factory()->create(['is_active' => false]);
    makeProduct(['slug' => 'hidden', 'collection_id' => $collection->id], 1);

    $this->getJson('/api/frontend/products/hidden')->assertNotFound();
});

it('returns 404 for a soft-deleted product', function () {
    makeProduct(['slug' => 'gone'], 1)->delete();

    $this->getJson('/api/frontend/products/gone')->assertNotFound();
});

it('returns 404 for an unknown slug', function () {
    $this->getJson('/api/frontend/products/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});
