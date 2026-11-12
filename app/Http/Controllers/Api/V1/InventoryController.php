<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryVariantResource;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(['low', 'out', 'in'])],
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $filter = $request->input('filter');

        $variants = ProductVariant::query()
            ->with('product:id,name,slug')
            ->when($filter === 'out', fn ($q) => $q->whereColumn('stock', '<=', 'reserved'))
            ->when($filter === 'low', fn ($q) => $q
                ->whereColumn('stock', '>', 'reserved')
                ->whereRaw('stock <= reserved + low_stock_threshold'))
            ->when($filter === 'in', fn ($q) => $q->whereRaw('stock > reserved + low_stock_threshold'))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';

                $q->where(fn ($w) => $w
                    ->where('sku', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->orderByDesc('id')
            ->paginate(15);

        return $this->paginated($variants, InventoryVariantResource::class, 'Inventory retrieved successfully.');
    }
}
