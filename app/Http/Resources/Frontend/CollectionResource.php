<?php

namespace App\Http\Resources\Frontend;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Collection */
class CollectionResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'products_count' => $this->whenCounted('products'),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand->only(['id', 'name', 'slug'])),
        ];
    }
}
