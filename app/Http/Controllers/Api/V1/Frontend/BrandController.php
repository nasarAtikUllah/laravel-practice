<?php

namespace App\Http\Controllers\Api\V1\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\BrandDetailResource;
use App\Http\Resources\Frontend\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $brands = Brand::query()
            ->where('is_active', true)
            ->withCount(['collections' => fn ($q) => $q->where('is_active', true)])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate(12);

        return $this->paginated($brands, BrandResource::class, 'Brands retrieved successfully.');
    }

    public function show(string $slug): JsonResponse
    {
        $brand = Brand::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->withCount(['collections' => fn ($q) => $q->where('is_active', true)])
            ->with(['collections' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->firstOrFail();

        return $this->success(BrandDetailResource::make($brand), 'Brand retrieved successfully.');
    }
}
