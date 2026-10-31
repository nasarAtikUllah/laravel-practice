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
    public function index(Request $request, Brand $brand): JsonResponse
    {
        $collections = $brand->collections()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest('id')
            ->paginate(15);

        return $this->paginated($collections, CollectionResource::class, 'Collections retrieved successfully.');
    }

    public function store(StoreCollectionRequest $request, Brand $brand): JsonResponse
    {
        $collection = $brand->collections()->create($request->validated());

        return $this->success(CollectionResource::make($collection), 'Collection created successfully.', 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        return $this->success(
            CollectionResource::make($collection->load('brand')),
            'Collection retrieved successfully.'
        );
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): JsonResponse
    {
        $collection->update($request->validated());

        return $this->success(
            CollectionResource::make($collection->load('brand')),
            'Collection updated successfully.'
        );
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();

        return $this->success(null, 'Collection deleted successfully.');
    }
}
