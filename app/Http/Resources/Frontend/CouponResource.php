<?php

namespace App\Http\Resources\Frontend;

use App\Enums\CouponScope;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'value' => $this->value,
            'max_discount' => $this->max_discount,
            'min_order_amount' => $this->min_order_amount,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            // The list of brands or products only matters for the scope that uses it.
            'brands' => $this->when(
                $this->scope === CouponScope::Brands,
                fn () => $this->brands->map(fn ($brand) => $brand->only(['id', 'name', 'slug']))->all()
            ),
            'products' => $this->when(
                $this->scope === CouponScope::Products,
                fn () => $this->products->map(fn ($product) => $product->only(['id', 'name', 'slug']))->all()
            ),
        ];
    }
}
