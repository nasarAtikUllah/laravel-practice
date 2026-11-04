<?php

namespace App\Http\Resources\Frontend;

use App\Http\Resources\MediaResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 *
 * @property string|float|null $price_from
 * @property bool|int|null $in_stock
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $featured = $this->getFirstMedia('featured_image');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'featured_image' => $featured ? MediaResource::make($featured) : null,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand->only(['name', 'slug'])),
            'collection' => $this->whenLoaded('collection', fn () => $this->collection->only(['name', 'slug'])),
            'price_from' => $this->price_from !== null ? number_format((float) $this->price_from, 2, '.', '') : null,
            // 'in_stock' => (int) $this->total_stock > 0,
            'in_stock' => (bool) $this->in_stock,
        ];
    }
}
