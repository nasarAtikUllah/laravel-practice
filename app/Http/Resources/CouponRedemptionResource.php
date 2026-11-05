<?php

namespace App\Http\Resources;

use App\Models\CouponRedemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CouponRedemption */
class CouponRedemptionResource extends JsonResource
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
            'coupon_id' => $this->coupon_id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'discount_amount' => $this->discount_amount,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
