<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderException;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Closure;
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

        $order->update(['number' => Order::numberFor($order->id)]);
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

    /**
     * Cancels an order and gives back what it took: the stock and the coupon use.
     * The order row is locked, so a cancel and any other change to the order cannot overlap.
     */
    public function cancel(Order $order, User $by, ?string $reason = null, bool $byStaff = false): Order
    {
        return DB::transaction(function () use ($order, $by, $reason, $byStaff) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (! $locked->status->canMoveTo(OrderStatus::Cancelled)) {
                throw OrderException::cannotCancel();
            }

            // A paid order needs a refund first (Day 4).
            if ($locked->payment_status === PaymentStatus::Paid) {
                throw $byStaff ? OrderException::paidNeedsRefundByStaff() : OrderException::paidNeedsRefund();
            }

            $reason = trim((string) $reason) !== ''
                ? trim((string) $reason)
                : ($byStaff ? 'Cancelled by staff' : 'Cancelled by customer');

            if ($locked->status === OrderStatus::PendingPayment) {
                // Online order: the stock was only held.
                InventoryReservation::query()
                    ->where('reference_type', 'order')
                    ->where('reference_id', $locked->id)
                    ->where('status', ReservationStatus::Active->value)
                    ->orderBy('product_variant_id')
                    ->get()
                    ->each(fn (InventoryReservation $reservation) => $this->inventory->releaseReservation($reservation));
            } else {
                // The stock was sold at checkout. Put it back.
                $this->inventory->restoreStockForOrder('order', $locked->id, $reason, $by);
            }

            // Gives back the coupon use, held or counted.
            $this->coupons->releaseForOrder('order', $locked->id);

            $from = $locked->status;

            $locked->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => $by->id,
                'expires_at' => null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $locked->id,
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'user_id' => $by->id,
                'note' => $reason,
            ]);

            return $locked;
        });
    }

    // ---------- Staff: confirm, ship, deliver ----------

    /** Cash on delivery: staff called the customer and confirmed the order. */
    public function confirm(Order $order, User $by, ?string $note = null): Order
    {
        return $this->move(
            $order,
            [OrderStatus::PendingConfirmation],
            OrderStatus::Confirmed,
            $by,
            $this->clean($note) ?? 'Confirmed by staff',
            OrderException::notConfirmable(),
            fn () => ['confirmed_at' => now(), 'expires_at' => null], // no more auto-cancel
        );
    }

    public function ship(Order $order, User $by, ?string $note = null): Order
    {
        return $this->move(
            $order,
            [OrderStatus::Confirmed],
            OrderStatus::Shipped,
            $by,
            $this->clean($note) ?? 'Shipped',
            OrderException::notShippable(),
            fn () => ['shipped_at' => now()],
        );
    }

    public function deliver(Order $order, User $by, ?string $note = null): Order
    {
        $cod = $order->payment_method === PaymentMethod::Cod;

        return $this->move(
            $order,
            [OrderStatus::Shipped],
            OrderStatus::Delivered,
            $by,
            $this->clean($note) ?? ($cod ? 'Delivered. Cash collected.' : 'Delivered'),
            OrderException::notDeliverable(),
            fn (Order $locked) => ['delivered_at' => now()]
                + ($locked->payment_method === PaymentMethod::Cod && $locked->payment_status === PaymentStatus::Unpaid
                    ? ['payment_status' => PaymentStatus::Paid]
                    : []),
        );
    }

    /**
     * One status change. The order row is locked and the status is checked again inside the lock,
     * so two staff members cannot move the same order at once.
     *
     * @param  array<int, OrderStatus>  $allowedFrom
     * @param  Closure(Order): array<string, mixed>  $changes
     */
    private function move(Order $order, array $allowedFrom, OrderStatus $to, User $by, string $note, OrderException $refusal, Closure $changes): Order
    {
        return DB::transaction(function () use ($order, $allowedFrom, $to, $by, $note, $refusal, $changes) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, $allowedFrom, true) || ! $locked->status->canMoveTo($to)) {
                throw $refusal;
            }

            $from = $locked->status;

            $locked->update(['status' => $to] + $changes($locked));

            OrderStatusHistory::create([
                'order_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $by->id,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}
