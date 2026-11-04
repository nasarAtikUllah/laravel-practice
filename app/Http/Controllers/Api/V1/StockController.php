<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustStockRequest;
use App\Http\Requests\Api\V1\CountStockRequest;
use App\Http\Resources\InventoryVariantResource;
use App\Http\Resources\StockMovementResource;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function adjust(AdjustStockRequest $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validated();
        $quantity = (int) $data['quantity'];

        $movement = $data['type'] === StockMovementType::Restock->value
            ? $this->inventory->restock($variant, $quantity, $data['reason'] ?? null, $request->user())
            : $this->inventory->damage($variant, $quantity, $data['reason'], $request->user());

        return $this->success($this->payload($movement, $variant), 'Stock adjusted successfully.', 201);
    }

    public function count(CountStockRequest $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validated();
        $counted = (int) $data['counted_quantity'];

        if ($counted < $variant->reserved) {
            return $this->error(
                "The counted quantity ({$counted}) is lower than the stock held for pending orders ({$variant->reserved}).",
                422
            );
        }

        $movement = $this->inventory->countTo($variant, $counted, $data['reason'] ?? null, $request->user());

        if ($movement === null) {
            return $this->success(
                $this->payload(null, $variant),
                'The stock already matches the counted quantity. No change was made.'
            );
        }

        return $this->success($this->payload($movement, $variant), 'Stock count saved successfully.', 201);
    }

    /** @return array<string, mixed> */
    private function payload(?InventoryMovement $movement, ProductVariant $variant): array
    {
        return [
            'movement' => $movement ? StockMovementResource::make($movement->load('creator:id,name')) : null,
            'variant' => InventoryVariantResource::make($variant->refresh()->load('product:id,name,slug')),
        ];
    }
}
