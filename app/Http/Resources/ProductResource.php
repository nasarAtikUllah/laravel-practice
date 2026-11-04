<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
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
            'description' => $this->description,
            'status' => $this->status->value,
            'featured_image' => $featured ? MediaResource::make($featured) : null,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand->only(['id', 'name', 'slug'])),
            'collection' => $this->whenLoaded('collection', fn () => $this->collection->only(['id', 'name', 'slug'])),
            'variants_count' => $this->whenCounted('variants'),
            'total_stock' => $this->whenHas('variants_sum_stock', fn ($sum) => (int) $sum),
            'in_stock' => $this->whenHas('variants_sum_stock', fn ($sum) => (int) $sum > 0),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
