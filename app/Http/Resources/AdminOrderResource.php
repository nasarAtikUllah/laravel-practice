<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;

/** @mixin Order */
class AdminOrderResource extends OrderResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'id' => $this->id,
            'customer' => $this->whenLoaded('user', fn () => $this->user->only(['id', 'name', 'email'])),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->only(['id', 'name'])),
            'available_actions' => $this->availableActions(),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'review_reason' => $payment->review_reason,
                'paid_at' => $payment->paid_at,
                'refunds' => $payment->refunds->map(fn ($refund) => [
                    'reference' => $refund->reference,
                    'amount' => $refund->amount,
                    'status' => $refund->status->value,
                ])->all(),
            ])->all()),
            // The staff view of the history shows who made each change.
            'history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry) => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'note' => $entry->note,
                'by' => $entry->user?->only(['id', 'name']),
                'at' => $entry->created_at,
            ])->all()),
        ]);
    }

    /** @return array<int, string> what staff can do with this order now */
    private function availableActions(): array
    {
        $actions = [];

        if ($this->status === OrderStatus::PendingConfirmation) {
            $actions[] = 'confirm';
        }

        if ($this->status === OrderStatus::Confirmed) {
            $actions[] = 'ship';
        }

        if ($this->status === OrderStatus::Shipped) {
            $actions[] = 'deliver';
        }

        if ($this->isCancellableByCustomer()) { // before shipping and not paid
            $actions[] = 'cancel';
        }

        return $actions;
    }
}
