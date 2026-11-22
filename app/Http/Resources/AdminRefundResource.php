<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
class AdminRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'payment_reference' => $this->whenLoaded('payment', fn () => $this->payment->reference),
            'order_number' => $this->whenLoaded('order', fn () => $this->order->number),
            'amount' => $this->amount,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'provider_refund_id' => $this->provider_refund_id,
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
