<?php

namespace App\Services;

use App\Exceptions\CartException;
use App\Exceptions\CouponException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\ShippingOption;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(private readonly CouponService $coupons) {}

    public static function maxQuantity(): int
    {
        $max = (int) config('cart.max_quantity_per_line', 20);

        return $max > 0 ? $max : 20;
    }

    public static function maxLines(): int
    {
        $max = (int) config('cart.max_lines', 30);

        return $max > 0 ? $max : 30;
    }

    public function cartFor(User $user): Cart
    {
        try {
            return Cart::firstOrCreate(['user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            // Two requests created the cart at the same moment.
            return Cart::where('user_id', $user->id)->firstOrFail();
        }
    }

    /** @return array<string, mixed> */
    public function view(User $user): array
    {
        return $this->build($this->cartFor($user), $user);
    }

    // ---------- Items ----------

    /** @return array<string, mixed> */
    public function addItem(User $user, int $variantId, int $quantity): array
    {
        DB::transaction(function () use ($user, $variantId, $quantity) {
            $cart = $this->lockedCart($user);
            $variant = $this->purchasable($variantId);
            $item = CartItem::where('cart_id', $cart->id)->where('product_variant_id', $variantId)->first();
            $newQuantity = ($item->quantity ?? 0) + $quantity;

            $this->assertQuantity($newQuantity);

            if ($variant->available < $newQuantity) {
                throw CartException::notEnoughStock();
            }

            if ($item === null) {
                if (CartItem::where('cart_id', $cart->id)->count() >= self::maxLines()) {
                    throw CartException::tooManyLines(self::maxLines());
                }

                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_variant_id' => $variantId,
                    'quantity' => $newQuantity,
                    'price_when_added' => $variant->price,
                ]);
            } else {
                // The first price stays, so a price change keeps showing.
                $item->update(['quantity' => $newQuantity]);
            }
        });

        return $this->view($user);
    }

    /** @return array<string, mixed> */
    public function setQuantity(User $user, int $variantId, int $quantity): array
    {
        DB::transaction(function () use ($user, $variantId, $quantity) {
            $cart = $this->lockedCart($user);
            $item = CartItem::where('cart_id', $cart->id)->where('product_variant_id', $variantId)->first()
                ?? throw CartException::itemNotInCart();

            $this->assertQuantity($quantity);

            $variant = $this->purchasable($variantId);

            if ($variant->available < $quantity) {
                throw CartException::notEnoughStock();
            }

            $item->update(['quantity' => $quantity]);
        });

        return $this->view($user);
    }

    /** @return array<string, mixed> */
    public function removeItem(User $user, int $variantId): array
    {
        DB::transaction(function () use ($user, $variantId) {
            $cart = $this->lockedCart($user);

            $deleted = CartItem::where('cart_id', $cart->id)->where('product_variant_id', $variantId)->delete();

            if ($deleted === 0) {
                throw CartException::itemNotInCart();
            }
        });

        return $this->view($user);
    }

    /** @return array<string, mixed> */
    public function clear(User $user): array
    {
        DB::transaction(function () use ($user) {
            $cart = $this->lockedCart($user);

            CartItem::where('cart_id', $cart->id)->delete();
            $cart->update(['coupon_code' => null]);
        });

        return $this->view($user);
    }

    // ---------- Coupon preview ----------

    /** @return array<string, mixed> */
    public function applyCoupon(User $user, string $code): array
    {
        DB::transaction(function () use ($user, $code) {
            $cart = $this->lockedCart($user);
            $data = $this->build($cart, $user);

            $items = $this->quotable($data);

            if ($items === []) {
                throw CartException::cartEmpty();
            }

            // Throws a CouponException with the reason when the code cannot be used.
            $this->coupons->quote($code, $items, $user);

            $cart->update(['coupon_code' => strtoupper(trim($code))]);
        });

        return $this->view($user);
    }

    /** @return array<string, mixed> */
    public function removeCoupon(User $user): array
    {
        $this->cartFor($user)->update(['coupon_code' => null]);

        return $this->view($user);
    }

    // ---------- Delivery option ----------

    /** @return array<string, mixed> */
    public function selectShipping(User $user, int $optionId): array
    {
        DB::transaction(function () use ($user, $optionId) {
            $cart = $this->lockedCart($user);
            $option = ShippingOption::query()->active()->find($optionId) ?? throw CartException::shippingUnavailable();

            // The minimum is measured on the goods total after the coupon.
            $goodsTotal = Money::cents($this->build($cart, $user)['total_after_discount']);

            if (! $option->isAvailableFor($goodsTotal)) {
                throw CartException::shippingMinimum(Money::format(Money::cents($option->min_order_amount)));
            }

            $cart->update(['shipping_option_id' => $option->id]);
        });

        return $this->view($user);
    }

    // ---------- Checkout preparation ----------

    /**
     * Checks everything again and returns a hash of what the customer is about to buy.
     * Checkout (next step) refuses the order when the hash no longer matches.
     * Price changes are shown once and then accepted.
     *
     * @return array<string, mixed>
     */
    public function prepare(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $cart = $this->lockedCart($user);
            $data = $this->build($cart, $user);

            $changes = [];

            foreach ($data['lines'] as $line) {
                if ($line['price_changed']) {
                    $changes[] = [
                        'variant_id' => $line['variant_id'],
                        'previous_price' => $line['previous_price'],
                        'unit_price' => $line['unit_price'],
                    ];
                }
            }

            if ($data['ready'] && $changes !== []) {
                foreach ($changes as $change) {
                    CartItem::where('cart_id', $cart->id)
                        ->where('product_variant_id', $change['variant_id'])
                        ->update(['price_when_added' => $change['unit_price']]);
                }

                $data = $this->build($cart, $user);
            }

            $data['price_changes'] = $changes;
            $data['expected_total'] = $data['grand_total'];

            return $data;
        });
    }

    /** @return array<string, mixed> */
    private function build(Cart $cart, User $user): array
    {
        $cart->load(['items.variant.product.media']);

        $ids = $cart->items->pluck('product_variant_id')->all();
        $live = $ids === []
            ? collect()
            : ProductVariant::purchasable()->whereIn('id', $ids)->get()->keyBy('id');

        $rows = [];

        foreach ($cart->items as $item) {
            $liveVariant = $live->get($item->product_variant_id);
            $variant = $liveVariant ?? $item->variant;
            // $product = $variant->product;
            $product = $item->variant->product;

            $issue = match (true) {
                $liveVariant === null => 'unavailable',
                $liveVariant->available < $item->quantity => 'not_enough_stock',
                default => null,
            };

            $unit = Money::cents($variant->price);
            $added = Money::cents($item->price_when_added);

            $rows[] = [
                'variant_id' => (int) $item->product_variant_id,
                'product' => ['id' => $product->id, 'name' => $product->name, 'slug' => $product->slug],
                'title' => $variant->title,
                'sku' => $variant->sku,
                'image' => $product->getFirstMediaUrl('featured_image', 'thumb') ?: null,
                'quantity' => $item->quantity,
                'unit' => $unit,
                'line' => $unit * $item->quantity,
                'previous' => ($liveVariant !== null && $added !== $unit) ? $added : null,
                'in_stock' => $liveVariant !== null && $liveVariant->available > 0,
                'issue' => $issue,
                'discount' => 0,
                'eligible' => false,
            ];
        }

        $ok = array_values(array_filter($rows, fn (array $row) => $row['issue'] === null));
        $itemsTotal = array_sum(array_column($ok, 'line'));

        $issues = [];

        if ($rows === []) {
            $issues[] = ['type' => 'cart_empty', 'message' => 'Your cart is empty.'];
        }

        foreach ($rows as $row) {
            if ($row['issue'] === 'unavailable') {
                $issues[] = [
                    'type' => 'unavailable',
                    'variant_id' => $row['variant_id'],
                    'message' => "{$row['product']['name']} ({$row['title']}) is no longer available.",
                ];
            } elseif ($row['issue'] === 'not_enough_stock') {
                $issues[] = [
                    'type' => 'not_enough_stock',
                    'variant_id' => $row['variant_id'],
                    'message' => "{$row['product']['name']} ({$row['title']}) does not have enough stock for the quantity in your cart.",
                ];
            }
        }

        // ----- coupon preview on the lines that can be bought -----
        $coupon = null;
        $discount = 0;

        if ($cart->coupon_code !== null) {
            if ($ok === []) {
                $coupon = ['code' => $cart->coupon_code, 'applied' => false, 'message' => 'No item in the cart can be bought.'];
            } else {
                try {
                    $quote = $this->coupons->quote($cart->coupon_code, $this->quotable(['lines' => $ok]), $user);

                    $discount = Money::cents($quote['discount']);
                    $byVariant = collect($quote['lines'])->keyBy('variant_id');

                    foreach ($rows as $i => $row) {
                        $line = $byVariant->get($row['variant_id']);

                        if ($line !== null && $row['issue'] === null) {
                            $rows[$i]['discount'] = Money::cents($line['discount']);
                            $rows[$i]['eligible'] = (bool) $line['eligible'];
                        }
                    }

                    $coupon = [
                        'code' => $quote['code'],
                        'applied' => true,
                        'scope' => $quote['scope'],
                        'discount' => $quote['discount'],
                        'eligible_total' => $quote['eligible_total'],
                        'message' => null,
                    ];
                } catch (CouponException $e) {
                    $coupon = ['code' => $cart->coupon_code, 'applied' => false, 'message' => $e->getMessage()];
                    $issues[] = ['type' => 'coupon_invalid', 'message' => $e->getMessage()];
                }
            }
        }

        $afterDiscount = $itemsTotal - $discount;

        // ----- delivery option -----
        $shipping = $this->resolveShipping($cart, $afterDiscount);
        $hasItems = $ok !== [];

        if ($hasItems && $shipping['issue'] !== null) {
            $issues[] = $shipping['issue'];
        }

        $delivery = ($hasItems && $shipping['selected'] !== null) ? Money::cents($shipping['selected']->fee) : 0;
        $grand = $afterDiscount + $delivery;

        $fingerprint = [
            'user' => $user->id,
            'lines' => array_map(fn (array $row) => [$row['variant_id'], $row['quantity'], $row['unit']], $ok),
            'coupon' => ($coupon['applied'] ?? false) ? $coupon['code'] : null,
            'discount' => $discount,
            'shipping' => $shipping['selected']?->id,
            'delivery' => $delivery,
        ];

        return [
            'currency' => Money::currency(),
            'lines' => array_map(fn (array $row) => [
                'variant_id' => $row['variant_id'],
                'product' => $row['product'],
                'title' => $row['title'],
                'sku' => $row['sku'],
                'image' => $row['image'],
                'quantity' => $row['quantity'],
                'unit_price' => Money::format($row['unit']),
                'line_total' => Money::format($row['line']),
                'price_changed' => $row['previous'] !== null,
                'previous_price' => $row['previous'] !== null ? Money::format($row['previous']) : null,
                'in_stock' => $row['in_stock'],
                'issue' => $row['issue'],
                'coupon_eligible' => $row['eligible'],
                'discount' => Money::format($row['discount']),
                'line_payable' => Money::format($row['line'] - $row['discount']),
            ], $rows),
            'coupon' => $coupon,
            'shipping' => [
                'selected' => $shipping['selected'] === null ? null : [
                    'id' => $shipping['selected']->id,
                    'code' => $shipping['selected']->code,
                    'name' => $shipping['selected']->name,
                    'fee' => Money::format(Money::cents($shipping['selected']->fee)),
                ],
                'options' => $shipping['options'],
            ],
            'shipping_option_id' => $shipping['selected']?->id,
            'items_total' => Money::format($itemsTotal),
            'discount' => Money::format($discount),
            'total_after_discount' => Money::format($afterDiscount),
            'delivery_fee' => Money::format($delivery),
            'grand_total' => Money::format($grand),
            'ready' => $rows !== [] && $issues === [],
            'issues' => $issues,
            'cart_hash' => hash_hmac('sha256', (string) json_encode($fingerprint), (string) config('app.key')),
        ];
    }

    /**
     * @return array{options: array<int, array<string, mixed>>, selected: ShippingOption|null, issue: array<string, string>|null}
     */
    private function resolveShipping(Cart $cart, int $goodsTotal): array
    {
        $options = ShippingOption::query()->active()->ordered()->get();

        $selected = null;
        $issue = null;

        if ($cart->shipping_option_id !== null) {
            $chosen = $options->firstWhere('id', $cart->shipping_option_id);

            if ($chosen !== null && $chosen->isAvailableFor($goodsTotal)) {
                $selected = $chosen;
            } else {
                $issue = ['type' => 'shipping_unavailable', 'message' => 'Your delivery option is no longer available. Please choose another one.'];
            }
        } else {
            $default = $options->firstWhere('is_default', true);

            if ($default !== null && $default->isAvailableFor($goodsTotal)) {
                $selected = $default;
            } else {
                $issue = ['type' => 'shipping_required', 'message' => 'Please choose a delivery option.'];
            }
        }

        return [
            'options' => $options->map(function (ShippingOption $option) use ($goodsTotal, $selected) {
                $available = $option->isAvailableFor($goodsTotal);

                return [
                    'id' => $option->id,
                    'code' => $option->code,
                    'name' => $option->name,
                    'description' => $option->description,
                    'estimated_days' => $option->estimated_days,
                    'fee' => Money::format(Money::cents($option->fee)),
                    'available' => $available,
                    'unavailable_reason' => $available
                        ? null
                        : 'Available for orders of '.Money::format(Money::cents($option->min_order_amount)).' or more.',
                    'selected' => $selected?->id === $option->id,
                ];
            })->values()->all(),
            'selected' => $selected,
            'issue' => $issue,
        ];
    }

    // ---------- Helpers ----------

    /**
     * The lines the coupon service may price: only the ones that can be bought.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{variant_id: int, quantity: int}>
     */
    private function quotable(array $data): array
    {
        $lines = array_filter($data['lines'], fn (array $line) => ($line['issue'] ?? null) === null);

        return array_values(array_map(
            fn (array $line) => ['variant_id' => $line['variant_id'], 'quantity' => $line['quantity']],
            $lines
        ));
    }

    private function lockedCart(User $user): Cart
    {
        return Cart::lockForUpdate()->findOrFail($this->cartFor($user)->id);
    }

    private function purchasable(int $variantId): ProductVariant
    {
        return ProductVariant::purchasable()->find($variantId) ?? throw CartException::itemUnavailable();
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity > self::maxQuantity()) {
            throw CartException::quantityTooHigh(self::maxQuantity());
        }
    }
}
