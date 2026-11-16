<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CartException;
use App\Exceptions\CouponException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderException;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ShippingOption;
use App\Models\User;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ship = seedShipping();
    $this->carts = app(CartService::class);
});

// ---------- Cash on delivery ----------

it('places a cash on delivery order', function () {
    $this->freezeTime();
    $variant = buyable(1000, ['stock' => 10]);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));

    expect($order->wasRecentlyCreated)->toBeTrue();
    expect($order->user_id)->toBe($this->user->id);
    expect($order->status)->toBe(OrderStatus::PendingConfirmation);
    expect($order->payment_method)->toBe(PaymentMethod::Cod);
    expect($order->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($order->currency)->toBe('BDT');
    expect($order->items_total)->toBe('2000.00');
    expect($order->discount_total)->toBe('0.00');
    expect($order->delivery_fee)->toBe('60.00');
    expect($order->grand_total)->toBe('2060.00');
    expect($order->number)->toBe(sprintf('ORD-%s-%06d', now()->format('YY-m-d'), $order->id));
    expect($order->placed_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($order->expires_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString());
    expect($order->confirmed_at)->toBeNull();
    expect($order->shipping_name)->toBe('Rahim Uddin');
    expect($order->shipping_phone)->toBe('01712345678');
    expect($order->shipping_district)->toBe('Dhaka');
});

it('takes the auto-cancel time of a cash on delivery order from the config', function () {
    $this->freezeTime();
    config(['inventory.cod_auto_cancel_hours' => 48]);

    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]));

    expect($order->expires_at->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString());
});

it('saves a copy of every line', function () {
    $variant = buyable(1200, ['title' => '24 cm', 'sku' => 'PAN-24', 'stock' => 10]);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 3]]));

    expect($order->items)->toHaveCount(1);

    $item = $order->items->first();

    expect($item->product_variant_id)->toBe($variant->id);
    expect($item->product_name)->toBe($variant->product->name);
    expect($item->variant_title)->toBe('24 cm');
    expect($item->sku)->toBe('PAN-24');
    expect($item->unit_price)->toBe('1200.00');
    expect($item->quantity)->toBe(3);
    expect($item->line_total)->toBe('3600.00');
    expect($item->discount_total)->toBe('0.00');
    expect($item->line_payable)->toBe('3600.00');
});

it('keeps the order as it was when the product changes later', function () {
    $variant = buyable(1000, ['title' => '24 cm', 'sku' => 'PAN-24', 'stock' => 10]);
    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]]));

    $variant->update(['price' => 2000, 'title' => 'Renamed', 'sku' => 'NEW-SKU']);
    $variant->product->update(['name' => 'Another name']);

    $item = $order->fresh()->items->first();

    expect($item->unit_price)->toBe('1000.00');
    expect($item->variant_title)->toBe('24 cm');
    expect($item->sku)->toBe('PAN-24');
    expect($item->product_name)->not->toBe('Another name');
    expect($order->fresh()->grand_total)->toBe('1060.00');
});

it('keeps the order when its variant is deleted', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]]));

    $variant->delete();

    expect($order->fresh()->items)->toHaveCount(1);
    expect($order->fresh()->items->first()->sku)->not->toBeNull();
});

it('sells the stock at once for cash on delivery', function () {
    $variant = buyable(1000, ['stock' => 10, 'reserved' => 0]);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]]));

    $fresh = $variant->fresh();

    expect($fresh->stock)->toBe(8);
    expect($fresh->reserved)->toBe(0);

    $movement = InventoryMovement::firstOrFail();

    expect($movement->type->value)->toBe('sale');
    expect($movement->quantity)->toBe(-2);
    expect($movement->reference_type)->toBe('order');
    expect($movement->reference_id)->toBe($order->id);
    expect($movement->created_by)->toBe($this->user->id);
    expect(InventoryReservation::count())->toBe(0);
});

it('uses the delivery option the customer chose', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $this->carts->addItem($this->user, $variant->id, 1);
    $this->carts->selectShipping($this->user, $this->ship['outside']->id);
    $hash = $this->carts->prepare($this->user)['cart_hash'];

    $order = placeOrder($this->user, $hash);

    expect($order->shipping_option_id)->toBe($this->ship['outside']->id);
    expect($order->shipping_option_code)->toBe('outside-city');
    expect($order->shipping_option_name)->toBe('Outside city delivery');
    expect($order->delivery_fee)->toBe('120.00');
    expect($order->grand_total)->toBe('1120.00');
});

it('keeps the delivery option name and fee when the admin changes the option later', function () {
    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]));

    $this->ship['inside']->update(['name' => 'Renamed', 'fee' => 99]);

    expect($order->fresh()->shipping_option_name)->toBe('Inside city delivery');
    expect($order->fresh()->delivery_fee)->toBe('60.00');
});

it('empties the cart but keeps the delivery choice', function () {
    $variant = buyable(1000, ['stock' => 10]);
    makeCoupon(['code' => 'SAVE10']);
    $this->carts->addItem($this->user, $variant->id, 1);
    $this->carts->selectShipping($this->user, $this->ship['outside']->id);
    $this->carts->applyCoupon($this->user, 'SAVE10');
    $hash = $this->carts->prepare($this->user)['cart_hash'];

    placeOrder($this->user, $hash);

    $cart = $this->carts->cartFor($this->user)->fresh();

    expect(CartItem::count())->toBe(0);
    expect($cart->coupon_code)->toBeNull();
    expect($cart->shipping_option_id)->toBe($this->ship['outside']->id);
});

it('writes the first row of the status history', function () {
    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]));

    $history = OrderStatusHistory::where('order_id', $order->id)->get();

    expect($history)->toHaveCount(1);
    expect($history->first()->from_status)->toBeNull();
    expect($history->first()->to_status)->toBe(OrderStatus::PendingConfirmation);
    expect($history->first()->user_id)->toBe($this->user->id);
    expect($history->first()->note)->toBe('Order placed');
});

it('gives each order its own number', function () {
    $first = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]));
    $second = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]));

    expect($second->number)->not->toBe($first->number);
    expect($second->id)->toBeGreaterThan($first->id);
});

it('keeps an order with a total of zero as cash on delivery', function () {
    ShippingOption::query()->delete();
    ShippingOption::factory()->asDefault()->create(['fee' => 0]);
    Coupon::factory()->percentage(100)->create(['code' => 'FREE100']);

    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]], 'FREE100'));

    expect($order->grand_total)->toBe('0.00');
    expect($order->status)->toBe(OrderStatus::PendingConfirmation);
    expect($order->payment_status)->toBe(PaymentStatus::Unpaid);
});

// ---------- Cash on delivery with a coupon ----------

it('counts the coupon use at once and saves a copy of the coupon', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $coupon = makeCoupon(['code' => 'SAVE10', 'value' => 10]);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]], 'SAVE10'));

    expect($order->items_total)->toBe('1000.00');
    expect($order->discount_total)->toBe('100.00');
    expect($order->grand_total)->toBe('960.00');
    expect($order->coupon_code)->toBe('SAVE10');
    expect($order->coupon_type)->toBe('percentage');
    expect($order->coupon_value)->toBe('10.00');
    expect($order->coupon_scope)->toBe('all');
    expect($order->items->first()->discount_total)->toBe('100.00');
    expect($order->items->first()->line_payable)->toBe('900.00');

    $redemption = CouponRedemption::firstOrFail();

    expect($redemption->coupon_id)->toBe($coupon->id);
    expect($redemption->user_id)->toBe($this->user->id);
    expect($redemption->reference_type)->toBe('order');
    expect($redemption->reference_id)->toBe($order->id);
    expect($redemption->status)->toBe(RedemptionStatus::Redeemed);
    expect($redemption->discount_amount)->toBe('100.00');
    expect($redemption->expires_at)->toBeNull();
});

it('keeps the coupon copy when the coupon changes later', function () {
    $coupon = makeCoupon(['code' => 'SAVE10', 'value' => 10]);
    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]], 'SAVE10'));

    $coupon->update(['value' => 50]);

    expect($order->fresh()->coupon_value)->toBe('10.00');
    expect($order->fresh()->discount_total)->toBe('100.00');
});

it('saves the discount only on the lines the coupon covers', function () {
    $a = Brand::factory()->create();
    $b = Brand::factory()->create();
    Coupon::factory()->forBrands([$a])->create(['code' => 'BRANDA']);

    $order = placeOrder($this->user, readyCart(
        $this->user,
        [[buyableForBrand($a, 1000), 1], [buyableForBrand($b, 1000), 1]],
        'BRANDA'
    ));

    expect($order->coupon_scope)->toBe('brands');
    expect($order->discount_total)->toBe('100.00');
    expect($order->grand_total)->toBe('1960.00');
    expect($order->items->pluck('discount_total')->all())->toBe(['100.00', '0.00']);
    expect($order->items->pluck('line_payable')->all())->toBe(['900.00', '1000.00']);
});

it('uses up a coupon with a limit of one use per customer', function () {
    makeCoupon(['code' => 'ONCE']);
    placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]], 'ONCE'));

    $this->carts->addItem($this->user, buyable(1000)->id, 1);

    expect(fn () => $this->carts->applyCoupon($this->user, 'ONCE'))
        ->toThrow(CouponException::class, 'This coupon code is not valid.');
});

// ---------- Pay now ----------

it('holds the stock and the coupon use of an online order and waits for payment', function () {
    $this->freezeTime();
    $variant = buyable(1000, ['stock' => 10, 'reserved' => 0]);
    makeCoupon(['code' => 'SAVE10']);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 2]], 'SAVE10'), ['payment_method' => 'online']);

    expect($order->status)->toBe(OrderStatus::PendingPayment);
    expect($order->payment_method)->toBe(PaymentMethod::Online);
    expect($order->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($order->confirmed_at)->toBeNull();
    expect($order->expires_at->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString());

    $fresh = $variant->fresh();

    expect($fresh->stock)->toBe(10);
    expect($fresh->reserved)->toBe(2);
    expect(InventoryMovement::count())->toBe(0);

    $reservation = InventoryReservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Active);
    expect($reservation->reference_id)->toBe($order->id);
    expect($reservation->quantity)->toBe(2);
    expect($reservation->expires_at->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString());

    $redemption = CouponRedemption::firstOrFail();

    expect($redemption->status)->toBe(RedemptionStatus::Reserved);
    expect($redemption->expires_at->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString());
});

it('uses the same hold time for the order, the stock and the coupon', function () {
    $this->freezeTime();
    config(['inventory.reservation_minutes' => 30]);
    makeCoupon(['code' => 'SAVE10']);

    $order = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]], 'SAVE10'), ['payment_method' => 'online']);

    $expected = now()->addMinutes(30)->toDateTimeString();

    expect($order->expires_at->toDateTimeString())->toBe($expected);
    expect(InventoryReservation::firstOrFail()->expires_at->toDateTimeString())->toBe($expected);
    expect(CouponRedemption::firstOrFail()->expires_at->toDateTimeString())->toBe($expected);
});

it('stops other customers from buying stock that an online order holds', function () {
    $variant = buyable(1000, ['stock' => 3, 'reserved' => 0]);
    placeOrder($this->user, readyCart($this->user, [[$variant, 3]]), ['payment_method' => 'online']);

    $other = User::factory()->create();

    expect(fn () => $this->carts->addItem($other, $variant->id, 1))
        ->toThrow(CartException::class, 'Not enough stock for this quantity.');
});

it('confirms an online order that has nothing to pay', function () {
    ShippingOption::query()->delete();
    ShippingOption::factory()->asDefault()->create(['fee' => 0]);
    Coupon::factory()->percentage(100)->create(['code' => 'FREE100']);
    $variant = buyable(1000, ['stock' => 10]);

    $order = placeOrder($this->user, readyCart($this->user, [[$variant, 1]], 'FREE100'), ['payment_method' => 'online']);

    expect($order->grand_total)->toBe('0.00');
    expect($order->status)->toBe(OrderStatus::Confirmed);
    expect($order->payment_status)->toBe(PaymentStatus::NotRequired);
    expect($order->confirmed_at)->not->toBeNull();
    expect($order->expires_at)->toBeNull();
    expect($order->statusHistory->first()->to_status)->toBe(OrderStatus::Confirmed);

    $fresh = $variant->fresh();

    expect($fresh->stock)->toBe(9);
    expect($fresh->reserved)->toBe(0);
    expect(InventoryReservation::count())->toBe(0);
    expect(CouponRedemption::firstOrFail()->status)->toBe(RedemptionStatus::Redeemed);
});

// ---------- Checks before the order is saved ----------

it('refuses a wrong cart hash and saves nothing', function () {
    $variant = buyable(1000, ['stock' => 10]);
    readyCart($this->user, [[$variant, 1]]);

    expect(fn () => placeOrder($this->user, str_repeat('a', 64)))
        ->toThrow(OrderException::class, 'Your cart changed since you prepared it. Please review your cart and try again.');

    expect(Order::count())->toBe(0);
    expect(OrderItem::count())->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
    expect(CartItem::count())->toBe(1);
});

it('refuses a cart that changed after it was prepared', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    $this->carts->addItem($this->user, buyable(500)->id, 1);

    expect(fn () => placeOrder($this->user, $hash))->toThrow(OrderException::class, 'Your cart changed');
    expect(Order::count())->toBe(0);
});

it('refuses a cart whose quantity changed after it was prepared', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    $this->carts->setQuantity($this->user, $variant->id, 2);

    expect(fn () => placeOrder($this->user, $hash))->toThrow(OrderException::class, 'Your cart changed');
});

it('refuses an order when the price changed after the cart was prepared', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    $variant->update(['price' => 1100]);

    expect(fn () => placeOrder($this->user, $hash))->toThrow(OrderException::class, 'Your cart changed');
    expect(Order::count())->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
});

it('refuses an order when the delivery option changed after the cart was prepared', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    $this->carts->selectShipping($this->user, $this->ship['outside']->id);

    expect(fn () => placeOrder($this->user, $hash))->toThrow(OrderException::class, 'Your cart changed');
});

it('refuses an empty cart', function () {
    $hash = $this->carts->prepare($this->user)['cart_hash'];

    expect(fn () => placeOrder($this->user, $hash))
        ->toThrow(OrderException::class, 'Your cart needs attention before checkout.');
    expect(Order::count())->toBe(0);
});

it('lists the problems of a cart that is not ready', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);
    $variant->product->update(['status' => ProductStatus::Draft]);

    try {
        placeOrder($this->user, $hash);
        $this->fail('The order should have been refused.');
    } catch (OrderException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors[0]['type'])->toBe('unavailable');
        expect($e->errors[0]['variant_id'])->toBe($variant->id);
    }

    expect(Order::count())->toBe(0);
});

it('refuses an order when stock ran out after the cart was prepared', function () {
    $variant = buyable(1000, ['stock' => 5]);
    $hash = readyCart($this->user, [[$variant, 4]]);

    $variant->update(['stock' => 3]);

    expect(fn () => placeOrder($this->user, $hash))->toThrow(OrderException::class);
    expect(Order::count())->toBe(0);
    expect($variant->fresh()->stock)->toBe(3);
});

it('refuses an order when the coupon is no longer valid', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $coupon = makeCoupon(['code' => 'SAVE10']);
    $hash = readyCart($this->user, [[$variant, 1]], 'SAVE10');

    $coupon->update(['is_active' => false]);

    try {
        placeOrder($this->user, $hash);
        $this->fail('The order should have been refused.');
    } catch (OrderException $e) {
        expect($e->errors[0]['type'])->toBe('coupon_invalid');
    }

    expect(Order::count())->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
    expect(CouponRedemption::count())->toBe(0);
});

it('refuses an order when the delivery option is no longer available', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $this->carts->addItem($this->user, $variant->id, 1);
    $this->carts->selectShipping($this->user, $this->ship['outside']->id);
    $hash = $this->carts->prepare($this->user)['cart_hash'];

    $this->ship['outside']->update(['is_active' => false]);

    try {
        placeOrder($this->user, $hash);
        $this->fail('The order should have been refused.');
    } catch (OrderException $e) {
        expect($e->errors[0]['type'])->toBe('shipping_unavailable');
    }
});

it('refuses an order when there is no delivery option at all', function () {
    ShippingOption::query()->delete();
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    try {
        placeOrder($this->user, $hash);
        $this->fail('The order should have been refused.');
    } catch (OrderException $e) {
        expect($e->errors[0]['type'])->toBe('shipping_required');
    }
});

// ---------- Retry with the same key ----------

it('gives the same order back when the same key is used again', function () {
    $variant = buyable(1000, ['stock' => 10]);
    makeCoupon(['code' => 'SAVE10']);
    $hash = readyCart($this->user, [[$variant, 2]], 'SAVE10');

    $first = placeOrder($this->user, $hash, [], 'the-same-key');
    $second = placeOrder($this->user, $hash, [], 'the-same-key');

    expect($first->wasRecentlyCreated)->toBeTrue();
    expect($second->wasRecentlyCreated)->toBeFalse();
    expect($second->id)->toBe($first->id);
    expect($second->items)->toHaveCount(1);
    expect(Order::count())->toBe(1);
    expect(OrderItem::count())->toBe(1);
    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);
    expect(CouponRedemption::count())->toBe(1);
    expect(OrderStatusHistory::count())->toBe(1);
});

it('gives the first order back even when the cart changed after the first request', function () {
    $variant = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$variant, 1]]);

    $first = placeOrder($this->user, $hash, [], 'the-same-key');
    $this->carts->addItem($this->user, buyable(500)->id, 1);

    expect(placeOrder($this->user, $hash, [], 'the-same-key')->id)->toBe($first->id);
    expect(Order::count())->toBe(1);
});

it('lets two customers use the same key', function () {
    $other = User::factory()->create();

    $mine = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]), [], 'shared-key');
    $theirs = placeOrder($other, readyCart($other, [[buyable(1000), 1]]), [], 'shared-key');

    expect($theirs->id)->not->toBe($mine->id);
    expect(Order::count())->toBe(2);
});

it('makes a new order for a new key when the cart has new items', function () {
    $first = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]), [], 'key-one');
    $second = placeOrder($this->user, readyCart($this->user, [[buyable(1000), 1]]), [], 'key-two');

    expect($second->id)->not->toBe($first->id);
});

// ---------- Nothing is saved when a step fails ----------

it('saves nothing when the stock of a later line fails', function () {
    $first = buyable(1000, ['stock' => 10]);
    $second = buyable(1000, ['stock' => 10]);
    $hash = readyCart($this->user, [[$first, 1], [$second, 1]]);

    $real = new InventoryService;

    $this->partialMock(InventoryService::class, function (MockInterface $mock) use ($real) {
        $calls = 0;

        $mock->shouldReceive('sell')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new InsufficientStockException(0, 1);
            }

            return $real->sell(...$args);
        });
    });

    expect(fn () => placeOrder($this->user, $hash))
        ->toThrow(OrderException::class, 'Some items are no longer available in the quantity you chose. Please review your cart.');

    expect(Order::count())->toBe(0);
    expect(OrderItem::count())->toBe(0);
    expect(OrderStatusHistory::count())->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect($first->fresh()->stock)->toBe(10);
    expect($second->fresh()->stock)->toBe(10);
    expect(CartItem::count())->toBe(2);
});

it('saves nothing when the coupon fails after the stock was taken', function () {
    $variant = buyable(1000, ['stock' => 10]);
    makeCoupon(['code' => 'SAVE10']);
    $hash = readyCart($this->user, [[$variant, 1]], 'SAVE10');

    $this->partialMock(CouponService::class, function (MockInterface $mock) {
        $mock->shouldReceive('redeem')->andThrow(CouponException::invalid());
    });

    expect(fn () => placeOrder($this->user, $hash))
        ->toThrow(CouponException::class, 'This coupon code is not valid.');

    expect(Order::count())->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
    expect(CartItem::count())->toBe(1);
});
