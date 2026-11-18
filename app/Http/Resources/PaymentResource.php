<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            // Only an attempt that can still be paid has an address to pay at.
            'checkout_url' => $this->isOpen() ? $this->checkout_url : null,
            'expires_at' => $this->expires_at,
            'failure_reason' => $this->failure_reason === 'gateway_error'
                ? 'The payment could not be started.'
                : $this->failure_reason,
            // Money that is with us but cannot be used for the order. The internal reason stays private.
            'refund_pending' => $this->review_reason !== null,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
