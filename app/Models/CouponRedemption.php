<?php

namespace App\Models;

use App\Enums\RedemptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RedemptionStatus $status
 */
class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'reference_type',
        'reference_id',
        'discount_amount',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'discount_amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
