<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $user_id
 * @property string|null $coupon_code
 * @property int|null $shipping_option_id
 */
class Cart extends Model
{
    protected $fillable = ['user_id', 'coupon_code', 'shipping_option_id'];

    /** @return BelongsTo<ShippingOption, $this> */
    public function shippingOption(): BelongsTo
    {
        return $this->belongsTo(ShippingOption::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
