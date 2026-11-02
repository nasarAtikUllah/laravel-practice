<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductVariantRequest;
use App\Http\Requests\Api\V1\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        $variants = $product->variants()
            ->with('media')
            ->latest('id')
            ->paginate(15);

        return $this->paginated($variants, ProductVariantResource::class, 'Variants retrieved successfully.');
    }

    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        // stock falls back to 0, so the response never shows null
        $variant = $product->variants()->create($request->validated() + ['stock' => 0]);

        return $this->success(
            ProductVariantResource::make($variant->load('media')),
            'Variant created successfully.',
            201
        );
    }

    public function show(ProductVariant $variant): JsonResponse
    {
        return $this->success(
            ProductVariantResource::make($variant->load('media')),
            'Variant retrieved successfully.'
        );
    }

    public function update(UpdateProductVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $variant->update($request->validated());

        return $this->success(
            ProductVariantResource::make($variant->load('media')),
            'Variant updated successfully.'
        );
    }

    public function destroy(ProductVariant $variant): JsonResponse
    {
        $productIsPublished = Product::query()
            ->whereKey($variant->product_id)
            ->where('status', ProductStatus::Published->value)
            ->exists();

        // count() skips soft-deleted variants on its own
        $isLastVariant = ProductVariant::where('product_id', $variant->product_id)->count() === 1;

        if ($productIsPublished && $isLastVariant) {
            return $this->error(
                'A published product needs at least one variant. Add another variant or change the product status first.',
                409
            );
        }

        $variant->delete();

        return $this->success(null, 'Variant deleted successfully.');
    }
}
