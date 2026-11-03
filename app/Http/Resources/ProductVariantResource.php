<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'title' => $this->title,
            'sku' => $this->sku,
            'price' => $this->price,
            'compare_price' => $this->compare_price,
            'stock' => $this->stock,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'low_stock_threshold' => $this->low_stock_threshold,
            'images' => MediaResource::collection($this->getMedia('images')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
