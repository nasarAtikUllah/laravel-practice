<?php

namespace App\Http\Controllers\Api\V1\Frontend;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\CollectionResource;
use App\Http\Resources\Frontend\ProductResource;
use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    /** The active collections of one brand, with the number of products on the website. */
    public function index(Request $request, int $brandId): JsonResponse
    {
        $brand = Brand::query()->where('is_active', true)->findOrFail($brandId);

        $collections = $brand->collections()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->whereIn('status', ProductStatus::visibleValues())])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate(12);

        return $this->paginated($collections, CollectionResource::class, 'Collections retrieved successfully.', [
            'brand' => $brand->only(['id', 'name', 'slug']),
        ]);
    }

    /** One collection with its brand, and the products inside it. */
    public function show(Request $request, int $collectionId): JsonResponse
    {
        $collection = Collection::query()
            ->where('is_active', true)
            ->whereHas('brand', fn ($q) => $q->where('is_active', true))
            ->with('brand:id,name,slug')
            ->withCount(['products' => fn ($q) => $q->whereIn('status', ProductStatus::visibleValues())])
            ->findOrFail($collectionId);

        $query = $collection->products()
            ->whereIn('status', ProductStatus::visibleValues())
            ->with('media')
            ->withMin('variants as price_from', 'price')
            ->withExists(['variants as in_stock' => fn ($q) => $q->whereColumn('stock', '>', 'reserved')])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'));

        match ($request->input('sort')) {
            'price_asc' => $query->orderBy('price_from'),
            'price_desc' => $query->orderByDesc('price_from'),
            'name' => $query->orderBy('name'),
            default => $query->latest('id'),
        };

        return $this->paginated($query->paginate(12), ProductResource::class, 'Collection retrieved successfully.', [
            'collection' => CollectionResource::make($collection)->resolve(),
        ]);
    }
}
