<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVariantImagesRequest;
use App\Http\Resources\MediaResource;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;

class VariantImageController extends Controller
{
    private const MAX_IMAGES = 8;

    public function store(StoreVariantImagesRequest $request, ProductVariant $variant): JsonResponse
    {
        $files = $request->file('images');

        if ($variant->getMedia('images')->count() + count($files) > self::MAX_IMAGES) {
            return $this->error('A variant can have at most '.self::MAX_IMAGES.' images.', 422);
        }

        $added = collect($files)->map(
            fn ($file) => $variant->addMedia($file)->toMediaCollection('images')
        );

        return $this->success(MediaResource::collection($added), 'Images uploaded successfully.', 201);
    }

    public function destroy(ProductVariant $variant, int $mediaId): JsonResponse
    {
        $media = $variant->media()->where('collection_name', 'images')->findOrFail($mediaId);
        $media->delete();

        return $this->success(null, 'Image deleted successfully.');
    }
}
