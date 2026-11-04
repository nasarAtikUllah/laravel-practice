<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Enums\RedemptionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property CouponType $type
 * @property string $value
 * @property string|null $max_discount
 * @property string|null $min_order_amount
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_user
 * @property bool $is_active
 */
class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_discount',
        'min_order_amount',
        'starts_at',
        'expires_at',
        'usage_limit_total',
        'usage_limit_per_user',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'usage_limit_total' => 'integer',
            'usage_limit_per_user' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are always saved trimmed and in capital letters. */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtoupper(trim($value)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function usedCount(): int
    {
        return $this->redemptions()->whereIn('status', RedemptionStatus::counting())->count();
    }

    /** The start time is included, the expiry time is not. */
    public function isWithinWindow(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->expires_at === null || $this->expires_at->gt($now));
    }

    public function state(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        $now = now();

        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return 'scheduled';
        }

        if ($this->expires_at !== null && $this->expires_at->lte($now)) {
            return 'expired';
        }

        return 'active';
    }
}
