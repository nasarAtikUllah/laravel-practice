<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['brand:id,name,slug', 'collection:id,name,slug', 'media'])
            ->withCount('variants')
            ->withSum('variants', 'stock')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('collection_id'), fn ($q) => $q->where('collection_id', $request->integer('collection_id')))
            ->latest('id')
            ->paginate(15);

        return $this->paginated($products, ProductResource::class, 'Products retrieved successfully.');
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= ProductStatus::Draft->value;

        $product = Product::create($data);

        return $this->success(
            ProductResource::make($this->withDetails($product)),
            'Product created successfully.',
            201
        );
    }

    public function show(Product $product): JsonResponse
    {
        return $this->success(
            ProductResource::make($this->withDetails($product)),
            'Product retrieved successfully.'
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        // Sending only collection_id: keep the brand in step with the collection.
        // The request already checked that they belong together.
        if (isset($data['collection_id']) && ! isset($data['brand_id'])) {
            $data['brand_id'] = $product->brand_id;
        }

        $product->update($data);

        return $this->success(
            ProductResource::make($this->withDetails($product)),
            'Product updated successfully.'
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        // The model frees the slug and deletes the variants one by one.
        DB::transaction(fn () => $product->delete());

        return $this->success(null, 'Product deleted successfully.');
    }

    private function withDetails(Product $product): Product
    {
        return $product
            ->load(['brand:id,name,slug', 'collection:id,name,slug', 'media', 'variants.media'])
            ->loadCount('variants')
            ->loadSum('variants', 'stock');
    }
}
