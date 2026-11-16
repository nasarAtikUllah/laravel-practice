<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $code
 * @property string $fee
 * @property string|null $min_order_amount
 * @property bool $is_default
 * @property bool $is_active
 */
class ShippingOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'estimated_days',
        'fee',
        'min_order_amount',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    /**
     * @param  Builder<ShippingOption>  $query
     * @return Builder<ShippingOption>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ShippingOption>  $query
     * @return Builder<ShippingOption>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** The minimum is measured on the goods total after the coupon, in paisa. */
    public function isAvailableFor(int $goodsTotalCents): bool
    {
        return $this->min_order_amount === null
            || $goodsTotalCents >= Money::cents($this->min_order_amount);
    }
}
