<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class AdminPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'validation_id' => $this->validation_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'card_type' => $this->card_type,
            'risk_level' => $this->risk_level,
            'failure_reason' => $this->failure_reason,
            // Money that is with us but cannot be used for the order. A person must decide.
            'review_reason' => $this->review_reason,
            'needs_review' => $this->review_reason !== null,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
            'order' => $this->whenLoaded('order', fn () => [
                'number' => $this->order->number,
                'status' => $this->order->status->value,
                'payment_status' => $this->order->payment_status->value,
            ]),
            'customer' => $this->when(
                $this->relationLoaded('order') && $this->order->relationLoaded('user'),
                fn () => $this->order->user->only(['id', 'name', 'email'])
            ),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'type' => $event->type,
                'result' => $event->result,
                'at' => $event->created_at,
            ])->all()),
        ];
    }
}
