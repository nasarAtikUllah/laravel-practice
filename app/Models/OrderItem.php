<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $product_variant_id
 * @property string $product_name
 * @property string $variant_title
 * @property string $sku
 * @property string $unit_price
 * @property int $quantity
 * @property string $line_total
 * @property string $discount_total
 * @property string $line_payable
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_variant_id',
        'product_id',
        'product_name',
        'variant_title',
        'sku',
        'unit_price',
        'quantity',
        'line_total',
        'discount_total',
        'line_payable',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'line_payable' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
