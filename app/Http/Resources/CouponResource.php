<?php

namespace App\Http\Resources;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Coupon
 *
 * @property string $code
 * @property CouponType $type
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property int|string|null $brands_count
 * @property int|string|null $products_count
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_user
 * @property bool $is_active
 * @property int|string|null $used_count
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'brands_count' => $this->whenCounted('brands'),
            'products_count' => $this->whenCounted('products'),
            'brands' => $this->whenLoaded('brands', fn () => $this->brands->map(fn ($brand) => $brand->only(['id', 'name', 'slug']))->all()),
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($product) => $product->only(['id', 'name', 'slug']))->all()),
            'value' => $this->value,
            'max_discount' => $this->max_discount,
            'min_order_amount' => $this->min_order_amount,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'usage_limit_total' => $this->usage_limit_total,
            'usage_limit_per_user' => $this->usage_limit_per_user,
            'is_active' => $this->is_active,
            'state' => $this->state(),
            'used_count' => (int) ($this->used_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
