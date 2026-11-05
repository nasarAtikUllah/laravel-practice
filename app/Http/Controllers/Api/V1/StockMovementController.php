<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryVariantResource;
use App\Http\Resources\StockMovementResource;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    public function index(Request $request, ProductVariant $variant): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
        ]);

        $movements = $variant->movements()
            ->with('creator:id,name')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderByDesc('id')
            ->paginate(20);

        $variant->load('product:id,name,slug');

        return $this->paginated($movements, StockMovementResource::class, 'Stock movements retrieved successfully.', [
            'variant' => InventoryVariantResource::make($variant),
        ]);
    }
}
