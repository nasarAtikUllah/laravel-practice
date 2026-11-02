<?php

namespace App\Http\Controllers\Api\V1\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\ProductDetailResource;
use App\Http\Resources\Frontend\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->visible()
            ->with(['brand:id,name,slug', 'collection:id,name,slug', 'media'])
            ->withMin('variants as price_from', 'price')
            ->withSum('variants as total_stock', 'stock')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('brand'), fn ($q) => $q->whereHas('brand', fn ($b) => $b->where('slug', $request->input('brand'))))
            ->when($request->filled('collection'), fn ($q) => $q->whereHas('collection', fn ($c) => $c->where('slug', $request->input('collection'))));

        match ($request->input('sort')) {
            'price_asc' => $query->orderBy('price_from'),
            'price_desc' => $query->orderByDesc('price_from'),
            'name' => $query->orderBy('name'),
            default => $query->latest('id'),
        };

        return $this->paginated($query->paginate(12), ProductResource::class, 'Products retrieved successfully.');
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->visible()
            ->where('slug', $slug)
            ->with([
                'brand:id,name,slug',
                'collection:id,name,slug',
                'media',
                'variants' => fn ($q) => $q->orderBy('price')->with('media'),
            ])
            ->firstOrFail();

        return $this->success(ProductDetailResource::make($product), 'Product retrieved successfully.');
    }
}
