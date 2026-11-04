<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class InventoryVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $available = $this->available;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product?->only(['id', 'name', 'slug'])),
            'title' => $this->title,
            'sku' => $this->sku,
            'stock' => $this->stock,
            'reserved' => $this->reserved,
            'available' => $available,
            'low_stock_threshold' => $this->low_stock_threshold,
            'status' => match (true) {
                $available <= 0 => 'out_of_stock',
                $available <= $this->low_stock_threshold => 'low_stock',
                default => 'in_stock',
            },
        ];
    }
}
