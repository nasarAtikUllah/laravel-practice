<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $payment_id
 * @property int $order_id
 * @property string $reference
 * @property string $amount
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $provider_refund_id
 * @property int|null $requested_by
 * @property int|null $resolved_by
 * @property string|null $failure_reason
 * @property string|null $resolution_note
 * @property int $attempts
 * @property string $idempotency_key
 * @property Carbon|null $processed_at
 * @property Carbon|null $resolved_at
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'order_id',
        'reference',
        'amount',
        'status',
        'reason',
        'provider_refund_id',
        'requested_by',
        'resolved_by',
        'failure_reason',
        'resolution_note',
        'attempts',
        'idempotency_key',
        'processed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
