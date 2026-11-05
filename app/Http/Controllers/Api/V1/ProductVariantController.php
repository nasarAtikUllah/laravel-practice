<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductVariantRequest;
use App\Http\Requests\Api\V1\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

    public function store(StoreProductVariantRequest $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $data = $request->validated();
        $initialStock = (int) ($data['stock'] ?? 0);
        unset($data['stock']);

        $variant = DB::transaction(function () use ($product, $data, $initialStock, $inventory, $request) {
            $variant = $product->variants()->create($data);

            // Stock never goes straight into the column. It goes through the ledger.
            $inventory->initial($variant, $initialStock, $request->user());

            return $variant->refresh();
        });

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
        return DB::transaction(function () use ($variant) {
            $locked = ProductVariant::lockForUpdate()->findOrFail($variant->id);

            $productIsPublished = Product::query()
                ->whereKey($locked->product_id)
                ->where('status', ProductStatus::Published->value)
                ->exists();

            // count() skips soft-deleted variants on its own
            $isLastVariant = ProductVariant::where('product_id', $locked->product_id)->count() === 1;

            if ($productIsPublished && $isLastVariant) {
                return $this->error(
                    'A published product needs at least one variant. Add another variant or change the product status first.',
                    409
                );
            }

            if ($locked->reserved > 0) {
                return $this->error(
                    "This variant has {$locked->reserved} unit(s) held for pending orders. Wait until those orders end before deleting it.",
                    409
                );
            }

            if ($locked->stock > 0) {
                return $this->error(
                    "This variant still has {$locked->stock} unit(s) in stock. Set its stock to 0 with a stock count first, so the change is recorded.",
                    409
                );
            }

            $locked->delete();

            return $this->success(null, 'Variant deleted successfully.');
        });
    }
}
