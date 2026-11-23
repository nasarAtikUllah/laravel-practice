<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 *
 * @property int|string|null $items_count
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payment_status->value,
            'requires_payment' => $this->status === OrderStatus::PendingPayment,
            'can_cancel' => $this->isCancellableByCustomer(),
            'currency' => $this->currency,
            'items_total' => $this->items_total,
            'discount_total' => $this->discount_total,
            'delivery_fee' => $this->delivery_fee,
            'grand_total' => $this->grand_total,
            'coupon' => $this->coupon_code === null ? null : [
                'code' => $this->coupon_code,
                'type' => $this->coupon_type,
                'value' => $this->coupon_value,
                'scope' => $this->coupon_scope,
            ],
            'shipping' => [
                'option' => $this->shipping_option_code === null ? null : [
                    'code' => $this->shipping_option_code,
                    'name' => $this->shipping_option_name,
                ],
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'address' => $this->shipping_address,
                'district' => $this->shipping_district,
            ],
            'note' => $this->note,
            'placed_at' => $this->placed_at,
            'expires_at' => $this->expires_at,
            'confirmed_at' => $this->confirmed_at,
            'shipped_at' => $this->shipped_at,
            'delivered_at' => $this->delivered_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'variant_id' => $item->product_variant_id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'variant_title' => $item->variant_title,
                'sku' => $item->sku,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'discount' => $item->discount_total,
                'line_payable' => $item->line_payable,
            ])->all()),
            'history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry) => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'note' => $entry->note,
                'at' => $entry->created_at,
            ])->all()),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($refund) => [
                'reference' => $refund->reference,
                'amount' => $refund->amount,
                'status' => $refund->status->value,
                'reason' => $refund->reason,
                'created_at' => $refund->created_at,
                'processed_at' => $refund->processed_at,
            ])->all()),
        ];
    }
}
