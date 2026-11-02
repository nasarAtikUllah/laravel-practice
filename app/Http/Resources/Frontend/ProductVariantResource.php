<?php

namespace App\Http\Resources\Frontend;

use App\Http\Resources\MediaResource;
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
        $price = (float) $this->price;
        $compare = $this->compare_price !== null ? (float) $this->compare_price : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'sku' => $this->sku,
            'price' => number_format($price, 2, '.', ''),
            'compare_price' => $compare !== null ? number_format($compare, 2, '.', '') : null,
            'discount_percent' => ($compare !== null && $compare > $price)
                ? (int) round(($compare - $price) / $compare * 100)
                : null,
            'in_stock' => $this->stock > 0,
            'images' => MediaResource::collection($this->getMedia('images')),
        ];
    }
}
