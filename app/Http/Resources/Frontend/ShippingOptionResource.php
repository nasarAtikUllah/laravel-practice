<?php

namespace App\Http\Resources\Frontend;

use App\Models\ShippingOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShippingOption */
class ShippingOptionResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'estimated_days' => $this->estimated_days,
            'fee' => $this->fee,
            'min_order_amount' => $this->min_order_amount,
        ];
    }
}
