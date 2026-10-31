<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBrandRequest;
use App\Http\Requests\Api\V1\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $brands = Brand::query()
            ->withCount('collections')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate(15);

        return $this->paginated($brands, BrandResource::class, 'Brands retrieved successfully.');
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = Brand::create($request->validated());

        return $this->success(BrandResource::make($brand), 'Brand created successfully.', 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        return $this->success(
            BrandResource::make($brand->loadCount('collections')),
            'Brand retrieved successfully.'
        );
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $brand->update($request->validated());

        return $this->success(
            BrandResource::make($brand->loadCount('collections')),
            'Brand updated successfully.'
        );
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->collections()->exists()) {
            return $this->error('This brand has collections. Delete or move them first.', 409);
        }

        $brand->delete();

        return $this->success(null, 'Brand deleted successfully.');
    }
}
