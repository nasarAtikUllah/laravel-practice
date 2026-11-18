<?php

namespace App\Models;

use App\Enums\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $order_id
 * @property string $provider
 * @property string $reference
 * @property string|null $provider_reference
 * @property string|null $session_key
 * @property string|null $validation_id
 * @property string $amount
 * @property string $currency
 * @property PaymentRecordStatus $status
 * @property string|null $checkout_url
 * @property string|null $card_type
 * @property int|null $risk_level
 * @property string|null $failure_reason
 * @property string|null $review_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 */
class Payment extends Model
{
    use HasFactory;

    public const REFERENCE_PATTERN = 'P[0-9]+-[A-Z0-9]{10}';

    protected $fillable = [
        'order_id',
        'provider',
        'reference',
        'provider_reference',
        'session_key',
        'validation_id',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'card_type',
        'risk_level',
        'failure_reason',
        'review_reason',
        'expires_at',
        'paid_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentRecordStatus::class,
            'amount' => 'decimal:2',
            'risk_level' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** The customer can still pay with this attempt. */
    public function isOpen(): bool
    {
        return $this->status === PaymentRecordStatus::Initiated
            && ($this->expires_at === null || $this->expires_at->gt(now()));
    }
}
