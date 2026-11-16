<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $product_variant_id
 * @property int $quantity
 * @property string $price_when_added
 */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_variant_id', 'quantity', 'price_when_added'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_when_added' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** A variant deleted after it was added still shows in the cart, marked as unavailable. */
    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
