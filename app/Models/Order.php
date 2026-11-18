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
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string $shipping_name
 * @property string $shipping_phone
 * @property string $shipping_address
 * @property string $shipping_district
 * @property string|null $note
 * @property string|null $coupon_type
 * @property string|null $coupon_value
 * @property string|null $coupon_scope
 * @property string|null $shipping_option_code
 * @property string|null $shipping_option_name
 */
class Order extends Model
{
    use HasFactory;

    /** An order number looks like ORD-261117-000042: the placed date (ymd), then the order id. */
    public const NUMBER_PATTERN = 'ORD-[0-9]{6}-[0-9]{6,}';

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

    public static function numberFor(int $id): string
    {
        return sprintf('ORD-%s-%06d', now()->format('ymd'), $id);
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

    /** A customer may cancel before shipping, but only when nothing was paid. */
    public function isCancellableByCustomer(): bool
    {
        return $this->status->canMoveTo(OrderStatus::Cancelled)
            && $this->payment_status !== PaymentStatus::Paid;
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
