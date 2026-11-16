<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $number
 * @property int $user_id
 * @property OrderStatus $status
 * @property PaymentMethod $payment_method
 * @property PaymentStatus $payment_status
 * @property string $currency
 * @property string $items_total
 * @property string $discount_total
 * @property string $delivery_fee
 * @property string $grand_total
 * @property string|null $coupon_code
 * @property Carbon $placed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'user_id',
        'status',
        'payment_method',
        'payment_status',
        'currency',
        'items_total',
        'discount_total',
        'delivery_fee',
        'grand_total',
        'coupon_code',
        'coupon_type',
        'coupon_value',
        'coupon_scope',
        'shipping_option_id',
        'shipping_option_code',
        'shipping_option_name',
        'shipping_name',
        'shipping_phone',
        'shipping_address',
        'shipping_district',
        'note',
        'idempotency_key',
        'placed_at',
        'expires_at',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'cancel_reason',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'coupon_value' => 'decimal:2',
            'placed_at' => 'datetime',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
