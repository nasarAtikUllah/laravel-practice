<?php

namespace App\Http\Resources\Frontend;

use App\Enums\ProductStatus;
use App\Http\Resources\MediaResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 *
 * @property string|float|null $price_from
 * @property int|string|null $total_stock
 */
class ProductDetailResource extends JsonResource
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
            'description' => $this->description,
            'status' => $this->status->value,
            'is_purchasable' => $this->status === ProductStatus::Published,
            'featured_image' => $featured ? MediaResource::make($featured) : null,
            'seo' => [
                'title' => $this->seo_title ?: $this->name,
                'description' => $this->seo_description,
            ],
            'brand' => $this->whenLoaded('brand', fn () => $this->brand->only(['name', 'slug'])),
            'collection' => $this->whenLoaded('collection', fn () => $this->collection->only(['name', 'slug'])),
            'in_stock' => $this->variants->contains('stock', '>', 0),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
