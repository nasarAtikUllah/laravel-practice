<?php

namespace App\Services;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\ProductStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\ProductVariant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /** 20,000,000.00. Keeps all the integer maths far from overflow. */
    public const MAX_CART_CENTS = 2_000_000_000;

    public static function reservationMinutes(): int
    {
        $minutes = (int) config('coupons.reservation_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }

    // ---------- Quote: shows the discount, uses nothing ----------

    /**
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array<string, mixed>
     */
    public function quote(string $code, array $items, User $user): array
    {
        $coupon = $this->findByCode($code) ?? throw CouponException::invalid();

        $this->assertUsable($coupon, $user);

        return $this->summary($coupon, $this->evaluate($coupon, $items));
    }

    // ---------- Order flow ----------

    /** Pay now: the use is held until payment succeeds or the time runs out. */
    public function reserve(string $code, User $user, array $items, string $referenceType, int $referenceId, ?int $minutes = null): CouponRedemption
    {
        return $this->claim($code, $user, $items, $referenceType, $referenceId, RedemptionStatus::Reserved, $minutes);
    }

    /** Cash on delivery: the use is counted at once and stays until the order is cancelled. */
    public function redeem(string $code, User $user, array $items, string $referenceType, int $referenceId): CouponRedemption
    {
        return $this->claim($code, $user, $items, $referenceType, $referenceId, RedemptionStatus::Redeemed, null);
    }

    /** Payment succeeded. */
    public function commit(CouponRedemption $redemption): CouponRedemption
    {
        return DB::transaction(function () use ($redemption) {
            $locked = CouponRedemption::lockForUpdate()->findOrFail($redemption->id);

            if ($locked->status === RedemptionStatus::Redeemed) {
                return $locked;
            }

            // Only the cleanup job ends a hold, so a late payment still works until it has run.
            if ($locked->status !== RedemptionStatus::Reserved) {
                throw CouponException::useEnded();
            }

            $locked->update(['status' => RedemptionStatus::Redeemed, 'expires_at' => null]);

            return $locked;
        });
    }

    /** Payment failed or the order was cancelled. Works on held and on counted uses. */
    public function release(CouponRedemption $redemption, RedemptionStatus $as = RedemptionStatus::Released): bool
    {
        return DB::transaction(function () use ($redemption, $as) {
            $locked = CouponRedemption::lockForUpdate()->findOrFail($redemption->id);

            if (! in_array($locked->status, [RedemptionStatus::Reserved, RedemptionStatus::Redeemed], true)) {
                return false;
            }

            // The cleanup job may only end a hold that is still waiting.
            if ($as === RedemptionStatus::Expired && $locked->status !== RedemptionStatus::Reserved) {
                return false;
            }

            $locked->update(['status' => $as]);

            return true;
        });
    }

    /** Gives back the coupon use of a cancelled order. Safe to call twice. */
    public function releaseForOrder(string $referenceType, int $referenceId): int
    {
        $released = 0;

        $redemptions = CouponRedemption::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('status', RedemptionStatus::counting())
            ->get();

        foreach ($redemptions as $redemption) {
            if ($this->release($redemption)) {
                $released++;
            }
        }

        return $released;
    }

    /** Called by the scheduler every minute. */
    public function releaseExpired(int $limit = 200): int
    {
        $ids = CouponRedemption::query()
            ->where('status', RedemptionStatus::Reserved->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $released = 0;

        foreach ($ids as $id) {
            $redemption = CouponRedemption::find($id);

            if ($redemption && $this->release($redemption, RedemptionStatus::Expired)) {
                $released++;
            }
        }

        return $released;
    }

    // ---------- Rules ----------

    private function claim(string $code, User $user, array $items, string $referenceType, int $referenceId, RedemptionStatus $status, ?int $minutes): CouponRedemption
    {
        return DB::transaction(function () use ($code, $user, $items, $referenceType, $referenceId, $status, $minutes) {
            // The lock makes two checkouts wait for each other, so the limit cannot be passed.
            $coupon = Coupon::query()->where('code', $this->normalize($code))->lockForUpdate()->first()
                ?? throw CouponException::invalid();

            $existing = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            // A retry of the same order: nothing more to do.
            if ($existing && in_array($existing->status, [RedemptionStatus::Reserved, RedemptionStatus::Redeemed], true)) {
                return $existing;
            }

            $hasOtherCoupon = CouponRedemption::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('coupon_id', '!=', $coupon->id)
                ->whereIn('status', RedemptionStatus::counting())
                ->exists();

            if ($hasOtherCoupon) {
                throw CouponException::alreadyHasCoupon();
            }

            $this->assertUsable($coupon, $user);

            $result = $this->evaluate($coupon, $items);

            $attributes = [
                'user_id' => $user->id,
                'discount_amount' => $this->fromCents($result['discount']),
                'status' => $status,
                'expires_at' => $status === RedemptionStatus::Reserved
                    ? now()->addMinutes($minutes ?? self::reservationMinutes())
                    : null,
            ];

            if ($existing) {
                // The same order tries again after its coupon use ended.
                $existing->update($attributes);

                return $existing;
            }

            return CouponRedemption::create($attributes + [
                'coupon_id' => $coupon->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    private function assertUsable(Coupon $coupon, User $user): void
    {
        if (! $coupon->is_active || ! $coupon->isWithinWindow()) {
            throw CouponException::invalid();
        }

        if ($coupon->usage_limit_total !== null && $coupon->usedCount() >= $coupon->usage_limit_total) {
            throw CouponException::invalid();
        }

        if ($coupon->usage_limit_per_user !== null) {
            $mine = $coupon->redemptions()
                ->where('user_id', $user->id)
                ->whereIn('status', RedemptionStatus::counting())
                ->count();

            if ($mine >= $coupon->usage_limit_per_user) {
                throw CouponException::invalid();
            }
        }
    }

    /**
     * Prices the cart, finds the lines the coupon covers, and works out the discount on those lines only.
     *
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array{lines: array<int, array<string, mixed>>, total: int, eligible_total: int, discount: int}
     */
    private function evaluate(Coupon $coupon, array $items): array
    {
        $cart = $this->priceCart($items);
        $isEligible = $this->eligibility($coupon);

        $lines = array_map(fn (array $line) => $line + ['eligible' => $isEligible($line)], $cart['lines']);

        $eligibleTotal = 0;

        foreach ($lines as $line) {
            if ($line['eligible']) {
                $eligibleTotal += $line['line_cents'];
            }
        }

        if ($eligibleTotal < 1) {
            throw CouponException::notApplicable();
        }

        $discount = $this->discountFor($coupon, $eligibleTotal);

        return [
            'lines' => $this->allocate($lines, $discount, $eligibleTotal),
            'total' => $cart['total'],
            'eligible_total' => $eligibleTotal,
            'discount' => $discount,
        ];
    }

    /** @return Closure(array<string, mixed>): bool */
    private function eligibility(Coupon $coupon): Closure
    {
        return match ($coupon->scope) {
            CouponScope::All => fn (array $line): bool => true,
            CouponScope::Brands => $this->matching($coupon->brands()->pluck('brands.id')->all(), 'brand_id'),
            CouponScope::Products => $this->matching($coupon->products()->pluck('products.id')->all(), 'product_id'),
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Closure(array<string, mixed>): bool
     */
    private function matching(array $ids, string $key): Closure
    {
        $ids = array_map('intval', $ids);

        return fn (array $line): bool => in_array((int) $line[$key], $ids, true);
    }

    /** The minimum and the discount are worked out on the covered items only. */
    private function discountFor(Coupon $coupon, int $eligibleTotal): int
    {
        $minimum = $coupon->min_order_amount !== null ? $this->toCents($coupon->min_order_amount) : 0;

        if ($minimum > 0 && $eligibleTotal < $minimum) {
            throw CouponException::minimumNotMet($this->fromCents($minimum), $coupon->scope !== CouponScope::All);
        }

        $valueCents = $this->toCents($coupon->value);

        if ($coupon->type === CouponType::Percentage) {
            // The value in cents is the percentage in basis points (12.50% = 1250). Half rounds up.
            $discount = intdiv($eligibleTotal * $valueCents + 5000, 10000);

            if ($coupon->max_discount !== null) {
                $discount = min($discount, $this->toCents($coupon->max_discount));
            }
        } else {
            $discount = $valueCents;
        }

        $discount = min($discount, $eligibleTotal);

        if ($discount < 1) {
            throw CouponException::noDiscount();
        }

        return $discount;
    }

    // ---------- Cart ----------

    /**
     * Prices always come from the database, never from the client.
     *
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array{lines: array<int, array<string, int>>, total: int}
     */
    private function priceCart(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }

        if ($quantities === []) {
            throw CouponException::itemsUnavailable();
        }

        $variants = ProductVariant::query()
            ->with('product:id,brand_id')
            ->whereIn('id', array_keys($quantities))
            ->whereHas('product', fn ($product) => $product
                ->whereNull('products.deleted_at') // the variant's product relation also finds deleted products
                ->where('status', ProductStatus::Published->value)
                ->whereHas('brand', fn ($brand) => $brand->where('is_active', true))
                ->whereHas('collection', fn ($collection) => $collection->where('is_active', true)))
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($quantities)) {
            throw CouponException::itemsUnavailable();
        }

        $lines = [];
        $total = 0;

        foreach ($quantities as $id => $quantity) {
            $variant = $variants[$id];
            $unit = $this->toCents($variant->price);
            $line = $unit * $quantity;
            $total += $line;

            $lines[] = [
                'variant_id' => $id,
                'product_id' => (int) $variant->product_id,
                'brand_id' => (int) $variant->product->brand_id,
                'quantity' => $quantity,
                'unit_cents' => $unit,
                'line_cents' => $line,
                'discount_cents' => 0,
            ];
        }

        if ($total > self::MAX_CART_CENTS) {
            throw CouponException::cartTooLarge();
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * Splits the discount over the covered lines only. Left-over cents go to the last lines first,
     * and never above a line's own price. The Orders module needs this split for refunds.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function allocate(array $lines, int $discount, int $eligibleTotal): array
    {
        $allocated = 0;

        foreach ($lines as $i => $line) {
            if (! $line['eligible']) {
                $lines[$i]['discount_cents'] = 0;

                continue;
            }

            $share = intdiv($discount * $line['line_cents'], $eligibleTotal);
            $lines[$i]['discount_cents'] = $share;
            $allocated += $share;
        }

        $remainder = $discount - $allocated;

        while ($remainder > 0) {
            $moved = false;

            for ($i = count($lines) - 1; $i >= 0 && $remainder > 0; $i--) {
                if ($lines[$i]['eligible'] && $lines[$i]['discount_cents'] < $lines[$i]['line_cents']) {
                    $lines[$i]['discount_cents']++;
                    $remainder--;
                    $moved = true;
                }
            }

            if (! $moved) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param  array{lines: array<int, array<string, mixed>>, total: int, eligible_total: int, discount: int}  $result
     * @return array<string, mixed>
     */
    private function summary(Coupon $coupon, array $result): array
    {
        return [
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'scope' => $coupon->scope->value,
            'value' => number_format((float) $coupon->value, 2, '.', ''),
            'items_total' => $this->fromCents($result['total']),
            'eligible_total' => $this->fromCents($result['eligible_total']),
            'discount' => $this->fromCents($result['discount']),
            'total_after_discount' => $this->fromCents($result['total'] - $result['discount']),
            'lines' => array_map(fn (array $line) => [
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $this->fromCents($line['unit_cents']),
                'line_total' => $this->fromCents($line['line_cents']),
                'eligible' => $line['eligible'],
                'discount' => $this->fromCents($line['discount_cents']),
                'line_payable' => $this->fromCents($line['line_cents'] - $line['discount_cents']),
            ], $result['lines']),
        ];
    }

    // ---------- Helpers ----------

    private function findByCode(string $code): ?Coupon
    {
        return Coupon::query()->where('code', $this->normalize($code))->first();
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
