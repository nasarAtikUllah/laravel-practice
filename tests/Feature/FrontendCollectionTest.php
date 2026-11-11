<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function shopCollection(array $attributes = [], ?Brand $brand = null): Collection
{
    return Collection::factory()->create(array_merge(
        ['brand_id' => ($brand ?? Brand::factory()->create())->id],
        $attributes
    ));
}

function shopProduct(Collection $collection, string $name, float $price = 1000, array $attributes = []): Product
{
    $product = makeProduct(array_merge([
        'collection_id' => $collection->id,
        'name' => $name,
        'slug' => Str::slug($name),
    ], $attributes), 0);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'price' => $price,
        'compare_price' => null,
        'stock' => 5,
        'reserved' => 0,
    ]);

    return $product;
}

// ---------- Collections of a brand ----------

it('lets guests list the collections of a brand with their product counts', function () {
    $brand = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);
    $kitchen = shopCollection(['name' => 'Kitchen', 'slug' => 'kitchen'], $brand);
    shopCollection(['name' => 'Bedroom', 'slug' => 'bedroom'], $brand);
    shopProduct($kitchen, 'Pan');
    shopProduct($kitchen, 'Pot');

    $this->getJson("/api/frontend/brands/{$brand->id}/collections")
        ->assertOk()
        ->assertJsonPath('message', 'Collections retrieved successfully.')
        ->assertJsonPath('brand.id', $brand->id)
        ->assertJsonPath('brand.slug', 'rfl')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Bedroom')
        ->assertJsonPath('data.0.products_count', 0)
        ->assertJsonPath('data.1.name', 'Kitchen')
        ->assertJsonPath('data.1.products_count', 2);
});

it('counts only the products the website shows', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Live');
    shopProduct($collection, 'Soon', 900, ['status' => ProductStatus::Upcoming]);
    shopProduct($collection, 'Draft One', 900, ['status' => ProductStatus::Draft]);
    shopProduct($collection, 'Archived One', 900, ['status' => ProductStatus::Archived]);
    shopProduct($collection, 'Gone')->delete();

    $this->getJson("/api/frontend/brands/{$collection->brand_id}/collections")
        ->assertOk()
        ->assertJsonPath('data.0.products_count', 2);
});

it('hides inactive collections', function () {
    $brand = Brand::factory()->create();
    shopCollection(['name' => 'Visible'], $brand);
    shopCollection(['name' => 'Hidden', 'is_active' => false], $brand);

    $this->getJson("/api/frontend/brands/{$brand->id}/collections")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible');
});

it('shows only the collections of the given brand', function () {
    $brand = Brand::factory()->create();
    shopCollection(['name' => 'Mine'], $brand);
    shopCollection(['name' => 'Other brand']);

    $this->getJson("/api/frontend/brands/{$brand->id}/collections")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine');
});

it('searches the collections of a brand by name', function () {
    $brand = Brand::factory()->create();
    shopCollection(['name' => 'Kitchen'], $brand);
    shopCollection(['name' => 'Bedroom'], $brand);

    $this->getJson("/api/frontend/brands/{$brand->id}/collections?search=kit")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Kitchen');
});

it('paginates 12 collections per page', function () {
    $brand = Brand::factory()->create();
    Collection::factory()->count(15)->create(['brand_id' => $brand->id]);

    $this->getJson("/api/frontend/brands/{$brand->id}/collections")
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 2);
});

it('does not expose private fields of a collection', function () {
    $collection = shopCollection();

    $this->getJson("/api/frontend/brands/{$collection->brand_id}/collections")
        ->assertOk()
        ->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.brand_id')
        ->assertJsonMissingPath('data.0.created_at');
});

it('returns 404 for an inactive, missing or non-numeric brand', function () {
    $inactive = Brand::factory()->inactive()->create();
    shopCollection([], $inactive);

    $this->getJson("/api/frontend/brands/{$inactive->id}/collections")
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
    $this->getJson('/api/frontend/brands/999/collections')->assertNotFound();
    $this->getJson('/api/frontend/brands/abc/collections')->assertNotFound();
});

it('uses the same number of queries however many collections a brand has', function () {
    $brand = Brand::factory()->create();
    Collection::factory()->count(2)->create(['brand_id' => $brand->id])
        ->each(fn ($c) => shopProduct($c, 'P '.$c->id));

    DB::enableQueryLog();
    $this->getJson("/api/frontend/brands/{$brand->id}/collections")->assertOk();
    $small = count(DB::getQueryLog());

    Collection::factory()->count(8)->create(['brand_id' => $brand->id])
        ->each(fn ($c) => shopProduct($c, 'P '.$c->id));

    DB::flushQueryLog();
    $this->getJson("/api/frontend/brands/{$brand->id}/collections")->assertOk();
    $large = count(DB::getQueryLog());

    expect($large)->toBe($small);
});

// ---------- One collection with its products ----------

it('shows a collection with its brand, product count and products', function () {
    $brand = Brand::factory()->create(['name' => 'RFL', 'slug' => 'rfl']);
    $collection = shopCollection(['name' => 'Kitchen', 'slug' => 'kitchen', 'description' => 'Pots and pans'], $brand);
    $pan = shopProduct($collection, 'Steel Pan', 1200);

    $this->getJson("/api/frontend/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Collection retrieved successfully.')
        ->assertJsonPath('collection.id', $collection->id)
        ->assertJsonPath('collection.name', 'Kitchen')
        ->assertJsonPath('collection.description', 'Pots and pans')
        ->assertJsonPath('collection.products_count', 1)
        ->assertJsonPath('collection.brand.slug', 'rfl')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pan->id)
        ->assertJsonPath('data.0.slug', 'steel-pan')
        ->assertJsonPath('data.0.price_from', '1200.00')
        ->assertJsonPath('data.0.in_stock', true)
        ->assertJsonPath('meta.total', 1);
});

it('lists only published and upcoming products of the collection', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Live');
    shopProduct($collection, 'Soon', 900, ['status' => ProductStatus::Upcoming]);
    shopProduct($collection, 'Draft One', 900, ['status' => ProductStatus::Draft]);
    shopProduct($collection, 'Archived One', 900, ['status' => ProductStatus::Archived]);
    shopProduct($collection, 'Gone')->delete();

    $slugs = collect($this->getJson("/api/frontend/collections/{$collection->id}")->assertOk()->json('data'))
        ->pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(['live', 'soon']);
});

it('does not list products of other collections', function () {
    $collection = shopCollection();
    $other = shopCollection(['name' => 'Other'], $collection->brand);
    shopProduct($collection, 'Mine');
    shopProduct($other, 'Not mine');

    $this->getJson("/api/frontend/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'mine');
});

it('returns an empty product list for a collection without products', function () {
    $collection = shopCollection();

    $this->getJson("/api/frontend/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonPath('collection.products_count', 0)
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('searches the products of a collection by name', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Steel Pan');
    shopProduct($collection, 'Glass Cup');

    $this->getJson("/api/frontend/collections/{$collection->id}?search=pan")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Steel Pan');
});

it('sorts the products of a collection', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Bravo', 200);
    shopProduct($collection, 'Charlie', 300);
    shopProduct($collection, 'Alpha', 100);

    $asc = $this->getJson("/api/frontend/collections/{$collection->id}?sort=price_asc")->json('data.*.price_from');
    $desc = $this->getJson("/api/frontend/collections/{$collection->id}?sort=price_desc")->json('data.*.price_from');
    $name = $this->getJson("/api/frontend/collections/{$collection->id}?sort=name")->json('data.*.name');

    expect($asc)->toBe(['100.00', '200.00', '300.00']);
    expect($desc)->toBe(['300.00', '200.00', '100.00']);
    expect($name)->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('shows the newest product first by default', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Older');
    shopProduct($collection, 'Newer');

    $this->getJson("/api/frontend/collections/{$collection->id}")->assertJsonPath('data.0.slug', 'newer');
});

it('paginates 12 products per page', function () {
    $collection = shopCollection();
    Product::factory()->count(15)->for($collection)->create();

    $this->getJson("/api/frontend/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('collection.products_count', 15);

    $this->getJson("/api/frontend/collections/{$collection->id}?page=2")->assertJsonCount(3, 'data');
});

it('shows a product as out of stock when all its stock is reserved', function () {
    $collection = shopCollection();
    $product = makeProduct(['collection_id' => $collection->id], 0);
    ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 5, 'reserved' => 5]);

    $this->getJson("/api/frontend/collections/{$collection->id}")->assertJsonPath('data.0.in_stock', false);
});

it('does not expose exact stock or private fields of the products', function () {
    $collection = shopCollection();
    shopProduct($collection, 'Steel Pan');

    $this->getJson("/api/frontend/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.0.stock')
        ->assertJsonMissingPath('data.0.total_stock')
        ->assertJsonMissingPath('data.0.variants')
        ->assertJsonMissingPath('data.0.description')
        ->assertJsonMissingPath('data.0.brand_id')
        ->assertJsonMissingPath('data.0.created_at')
        ->assertJsonMissingPath('collection.is_active')
        ->assertJsonMissingPath('collection.brand_id');
});

it('returns 404 for an inactive collection, an inactive brand or a missing collection', function () {
    $inactiveCollection = shopCollection(['is_active' => false]);
    $inactiveBrand = Brand::factory()->inactive()->create();
    $collectionOfInactiveBrand = shopCollection([], $inactiveBrand);

    $this->getJson("/api/frontend/collections/{$inactiveCollection->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
    $this->getJson("/api/frontend/collections/{$collectionOfInactiveBrand->id}")->assertNotFound();
    $this->getJson('/api/frontend/collections/999')->assertNotFound();
    $this->getJson('/api/frontend/collections/abc')->assertNotFound();
});

it('uses the same number of queries however many products the collection has', function () {
    $collection = shopCollection();
    shopProduct($collection, 'One');
    shopProduct($collection, 'Two');

    DB::enableQueryLog();
    $this->getJson("/api/frontend/collections/{$collection->id}")->assertOk();
    $small = count(DB::getQueryLog());

    foreach (range(1, 8) as $i) {
        shopProduct($collection, "More {$i}");
    }

    DB::flushQueryLog();
    $this->getJson("/api/frontend/collections/{$collection->id}")->assertOk();
    $large = count(DB::getQueryLog());

    expect($large)->toBe($small);
});
