<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductImageRequest;
use App\Http\Resources\MediaResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductImageController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $media = $product->addMediaFromRequest('image')->toMediaCollection('featured_image');

        return $this->success(MediaResource::make($media), 'Featured image saved successfully.', 201);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->clearMediaCollection('featured_image');

        return $this->success(null, 'Featured image removed successfully.');
    }
}
