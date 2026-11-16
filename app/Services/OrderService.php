<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Turns the customer's cart into an order. Everything happens in one transaction:
     * if any step fails, no order, no stock change and no coupon use is saved.
     *
     * @param  array<string, mixed>  $input  cart_hash, payment_method, shipping_name, shipping_phone,
     *                                       shipping_address, shipping_district, note
     */
    public function checkout(User $user, array $input, string $idempotencyKey): Order
    {
        try {
            return DB::transaction(fn () => $this->place($user, $input, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same key got through at once. Return the first order.
            $existing = Order::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing->load(['items', 'statusHistory']);
        }
    }

    /** @param array<string, mixed> $input */
    private function place(User $user, array $input, string $key): Order
    {
        // The cart row is locked first, so two checkouts of the same cart wait for each other.
        $cart = $this->carts->lock($user);

        // A retry with the same key gets the first order back and nothing else happens.
        $existing = Order::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing->load(['items', 'statusHistory']);
        }

        $data = $this->carts->describe($cart, $user);

        if (! $data['ready']) {
            throw OrderException::cartNotReady($data['issues']);
        }

        if (! hash_equals((string) $data['cart_hash'], (string) $input['cart_hash'])) {
            throw OrderException::cartChanged();
        }

        $method = PaymentMethod::from((string) $input['payment_method']);
        $grandCents = Money::cents($data['grand_total']);
        $minutes = InventoryService::reservationMinutes();

        // Cash on delivery, and an order with nothing to pay online, take the stock at once.
        $sellNow = $method === PaymentMethod::Cod || $grandCents === 0;

        [$status, $paymentStatus, $expiresAt] = match (true) {
            $method === PaymentMethod::Cod => [
                OrderStatus::PendingConfirmation,
                PaymentStatus::Unpaid,
                now()->addHours(InventoryService::codAutoCancelHours()),
            ],
            $grandCents === 0 => [OrderStatus::Confirmed, PaymentStatus::NotRequired, null],
            default => [OrderStatus::PendingPayment, PaymentStatus::Unpaid, now()->addMinutes($minutes)],
        };

        $order = Order::create([
            'number' => 'TMP-'.Str::uuid(), // replaced below, when the id is known
            'user_id' => $user->id,
            'status' => $status,
            'payment_method' => $method,
            'payment_status' => $paymentStatus,
            'currency' => $data['currency'],
            'items_total' => $data['items_total'],
            'discount_total' => $data['discount'],
            'delivery_fee' => $data['delivery_fee'],
            'grand_total' => $data['grand_total'],
            'shipping_option_id' => $data['shipping_option_id'],
            'shipping_option_code' => $data['shipping']['selected']['code'] ?? null,
            'shipping_option_name' => $data['shipping']['selected']['name'] ?? null,
            'shipping_name' => $input['shipping_name'],
            'shipping_phone' => $input['shipping_phone'],
            'shipping_address' => $input['shipping_address'],
            'shipping_district' => $input['shipping_district'],
            'note' => $input['note'] ?? null,
            'idempotency_key' => $key,
            'placed_at' => now(),
            'expires_at' => $expiresAt,
            'confirmed_at' => $status === OrderStatus::Confirmed ? now() : null,
        ]);

        $order->update(['number' => sprintf('ORD-%s-%06d', now()->format('YY-m-d'), $order->id)]);

        foreach ($data['lines'] as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $line['variant_id'],
                'product_id' => $line['product']['id'],
                'product_name' => $line['product']['name'],
                'variant_title' => $line['title'],
                'sku' => $line['sku'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'discount_total' => $line['discount'],
                'line_payable' => $line['line_payable'],
            ]);
        }

        // Stock: variants are always handled in the same order, so two checkouts cannot block each other.
        foreach (collect($data['lines'])->sortBy('variant_id') as $line) {
            try {
                $variant = ProductVariant::findOrFail($line['variant_id']);

                if ($sellNow) {
                    $this->inventory->sell($variant, $line['quantity'], 'order', $order->id, $user);
                } else {
                    $this->inventory->reserve($variant, $line['quantity'], 'order', $order->id, $minutes);
                }
            } catch (InsufficientStockException|ModelNotFoundException) {
                throw OrderException::itemsChanged();
            }
        }

        // Coupon: counted now (cash on delivery, nothing to pay) or held until payment.
        if ($data['coupon'] !== null && $data['coupon']['applied']) {
            $items = array_map(
                fn (array $line) => ['variant_id' => $line['variant_id'], 'quantity' => $line['quantity']],
                $data['lines']
            );

            $redemption = $sellNow
                ? $this->coupons->redeem($data['coupon']['code'], $user, $items, 'order', $order->id)
                : $this->coupons->reserve($data['coupon']['code'], $user, $items, 'order', $order->id, $minutes);

            // The coupon service worked out the discount again. It must be the one the customer saw.
            if (Money::cents($redemption->discount_amount) !== Money::cents($data['discount'])) {
                throw OrderException::cartChanged();
            }

            $coupon = $redemption->coupon;

            $order->update([
                'coupon_code' => $coupon->code,
                'coupon_type' => $coupon->type->value,
                'coupon_value' => $coupon->value,
                'coupon_scope' => $coupon->scope->value,
            ]);
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $status,
            'user_id' => $user->id,
            'note' => 'Order placed',
        ]);

        $this->carts->emptyAfterOrder($cart);

        return $order->load(['items', 'statusHistory']);
    }
}
