<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Requests\Api\V1\UpdateCollectionRequest;
use App\Http\Resources\CollectionResource;
use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index(Request $request, ?Brand $brand = null): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
        ]);

        $brandId = $brand?->id ?? ($request->filled('brand_id') ? $request->integer('brand_id') : null);
        $search = trim((string) $request->input('search'));

        $collections = Collection::query()
            ->with('brand:id,name,slug')
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount('products')
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return $this->paginated($collections, CollectionResource::class, 'Collections retrieved successfully.');
    }

    public function store(StoreCollectionRequest $request, Brand $brand): JsonResponse
    {
        $collection = $brand->collections()->create($request->validated())->refresh()->loadCount('products');

        return $this->success(CollectionResource::make($collection), 'Collection created successfully.', 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        // show and update: load the count with the brand
        return $this->success(
            CollectionResource::make($collection->load('brand')->loadCount('products')),
            'Collection retrieved successfully.'
        );
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): JsonResponse
    {
        $collection->update($request->validated());

        return $this->success(
            CollectionResource::make($collection->load('brand')->loadCount('products')),
            'Collection updated successfully.'
        );
    }

    public function destroy(Collection $collection): JsonResponse
    {
        if ($collection->products()->withTrashed()->exists()) {
            return $this->error('This collection has products. Move or delete them first.', 409);
        }

        $collection->delete();

        return $this->success(null, 'Collection deleted successfully.');
    }
}
