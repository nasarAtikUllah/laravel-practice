<?php

use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects guests on the image routes', function () {
    $product = makeProduct();
    $variant = $product->variants()->first();

    $this->postJson("/api/products/{$product->id}/featured-image", [])->assertUnauthorized();
    $this->deleteJson("/api/products/{$product->id}/featured-image")->assertUnauthorized();
    $this->postJson("/api/variants/{$variant->id}/images", [])->assertUnauthorized();
    $this->deleteJson("/api/variants/{$variant->id}/images/1")->assertUnauthorized();
});

describe('product featured image', function () {
    beforeEach(function () {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $this->product = makeProduct();
        $this->url = "/api/products/{$this->product->id}/featured-image";
    });

    it('uploads a featured image', function () {
        $this->postJson($this->url, ['image' => fakePhoto('pan.jpg')])
            ->assertCreated()
            ->assertJsonPath('message', 'Featured image saved successfully.')
            ->assertJsonStructure(['data' => ['id', 'url', 'thumb', 'size']]);

        expect($this->product->fresh()->getMedia('featured_image'))->toHaveCount(1);
    });

    it('stores the file and creates a thumbnail', function () {
        $this->postJson($this->url, ['image' => fakePhoto('pan.jpg')])->assertCreated();

        $media = $this->product->fresh()->getFirstMedia('featured_image');

        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
        expect($media->hasGeneratedConversion('thumb'))->toBeTrue();
    });

    it('accepts a png image', function () {
        $this->postJson($this->url, ['image' => fakePhoto('pan.png')])->assertCreated();
    });

    it('replaces the old featured image', function () {
        $this->postJson($this->url, ['image' => fakePhoto('one.jpg')])->assertCreated();
        $first = $this->product->fresh()->getFirstMedia('featured_image');
        $firstPath = $first->getPathRelativeToRoot();

        $this->postJson($this->url, ['image' => fakePhoto('two.jpg')])->assertCreated();

        $media = $this->product->fresh()->getMedia('featured_image');

        expect($media)->toHaveCount(1);
        expect($media->first()->id)->not->toBe($first->id);
        Storage::disk('public')->assertMissing($firstPath);
    });

    it('shows the featured image in the product response', function () {
        $this->postJson($this->url, ['image' => fakePhoto('pan.jpg')])->assertCreated();

        $response = $this->getJson("/api/products/{$this->product->id}")->assertOk();

        expect($response->json('data.featured_image.url'))->not->toBeNull();
        expect($response->json('data.featured_image.thumb'))->not->toBeNull();
    });

    it('rejects a pdf file', function () {
        $this->postJson($this->url, ['image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    });

    it('rejects an svg file', function () {
        $this->postJson($this->url, ['image' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    });

    it('rejects an image larger than 10 MB', function () {
        $this->postJson($this->url, ['image' => UploadedFile::fake()->image('big.jpg')->size(12000)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    });

    it('requires an image', function () {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    });

    it('removes the featured image', function () {
        $this->postJson($this->url, ['image' => fakePhoto('pan.jpg')])->assertCreated();
        $path = $this->product->fresh()->getFirstMedia('featured_image')->getPathRelativeToRoot();

        $this->deleteJson($this->url)
            ->assertOk()
            ->assertJsonPath('message', 'Featured image removed successfully.');

        expect($this->product->fresh()->getMedia('featured_image'))->toHaveCount(0);
        Storage::disk('public')->assertMissing($path);
    });

    it('succeeds when removing a featured image that does not exist', function () {
        $this->deleteJson($this->url)->assertOk();
    });

    it('returns 404 for a missing product', function () {
        $this->postJson('/api/products/999/featured-image', ['image' => fakePhoto()])->assertNotFound();
        $this->deleteJson('/api/products/999/featured-image')->assertNotFound();
    });
});

describe('variant images', function () {
    beforeEach(function () {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $this->product = makeProduct([], 0);
        $this->variant = ProductVariant::factory()->create(['product_id' => $this->product->id]);
        $this->url = "/api/variants/{$this->variant->id}/images";
    });

    it('uploads several images', function () {
        $this->postJson($this->url, ['images' => [fakePhoto('a.jpg'), fakePhoto('b.jpg')]])
            ->assertCreated()
            ->assertJsonPath('message', 'Images uploaded successfully.')
            ->assertJsonCount(2, 'data');

        expect($this->variant->fresh()->getMedia('images'))->toHaveCount(2);
    });

    it('stores the files and creates thumbnails', function () {
        $this->postJson($this->url, ['images' => [fakePhoto('a.jpg')]])->assertCreated();

        $media = $this->variant->fresh()->getFirstMedia('images');

        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
    });

    it('keeps the old images when new ones are added', function () {
        $this->postJson($this->url, ['images' => [fakePhoto('a.jpg')]])->assertCreated();
        $this->postJson($this->url, ['images' => [fakePhoto('b.jpg')]])->assertCreated();

        expect($this->variant->fresh()->getMedia('images'))->toHaveCount(2);
    });

    it('shows the images in the variant response', function () {
        $this->postJson($this->url, ['images' => [fakePhoto('a.jpg'), fakePhoto('b.jpg')]])->assertCreated();

        $this->getJson("/api/variants/{$this->variant->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.images');
    });

    it('rejects more than 5 images in one request', function () {
        $files = array_map(fn ($i) => fakePhoto("p{$i}.jpg"), range(1, 6));

        $this->postJson($this->url, ['images' => $files])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    });

    it('rejects uploads that would pass 8 images in total', function () {
        $first = array_map(fn ($i) => fakePhoto("a{$i}.jpg"), range(1, 5));
        $second = array_map(fn ($i) => fakePhoto("b{$i}.jpg"), range(1, 3));

        $this->postJson($this->url, ['images' => $first])->assertCreated();
        $this->postJson($this->url, ['images' => $second])->assertCreated();

        $this->postJson($this->url, ['images' => [fakePhoto('extra.jpg')]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A variant can have at most 8 images.');

        expect($this->variant->fresh()->getMedia('images'))->toHaveCount(8);
    });

    it('rejects a file that is not an image', function () {
        $this->postJson($this->url, ['images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');
    });

    it('rejects an image larger than 2 MB', function () {
        $this->postJson($this->url, ['images' => [UploadedFile::fake()->image('big.jpg')->size(3000)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');
    });

    it('requires images', function () {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    });

    it('deletes one image', function () {
        $this->postJson($this->url, ['images' => [fakePhoto('a.jpg'), fakePhoto('b.jpg')]])->assertCreated();
        $media = $this->variant->fresh()->getMedia('images')->first();
        $path = $media->getPathRelativeToRoot();

        $this->deleteJson("{$this->url}/{$media->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Image deleted successfully.');

        expect($this->variant->fresh()->getMedia('images'))->toHaveCount(1);
        Storage::disk('public')->assertMissing($path);
    });

    it('cannot delete an image that belongs to another variant', function () {
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id]);
        $otherMedia = $other->addMedia(fakePhoto('x.jpg'))->toMediaCollection('images');

        $this->deleteJson("{$this->url}/{$otherMedia->id}")->assertNotFound();

        expect($other->fresh()->getMedia('images'))->toHaveCount(1);
    });

    it('returns 404 for a missing image', function () {
        $this->deleteJson("{$this->url}/999")->assertNotFound();
    });

    it('returns 404 for a missing variant', function () {
        $this->postJson('/api/variants/999/images', ['images' => [fakePhoto()]])->assertNotFound();
    });
});
