<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\RedemptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCouponRequest;
use App\Http\Requests\Api\V1\UpdateCouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'state' => ['nullable', Rule::in(['active', 'inactive', 'scheduled', 'expired'])],
            'type' => ['nullable', Rule::enum(CouponType::class)],
            'scope' => ['nullable', Rule::enum(CouponScope::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $state = $request->input('state');
        $now = now();

        $coupons = Coupon::query()
            ->withCount([
                'redemptions as used_count' => fn ($q) => $q->whereIn('status', RedemptionStatus::counting()),
                'brands',
                'products',
            ])
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%'.strtoupper(trim((string) $request->input('search'))).'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->input('scope')))
            ->when($state === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($state === 'scheduled', fn ($q) => $q->where('is_active', true)->where('starts_at', '>', $now))
            ->when($state === 'expired', fn ($q) => $q->where('is_active', true)->where('expires_at', '<=', $now))
            ->when($state === 'active', fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $now)))
            ->latest('id')
            ->paginate(15);

        return $this->paginated($coupons, CouponResource::class, 'Coupons retrieved successfully.');
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['scope'] ??= CouponScope::All->value;

        $coupon = DB::transaction(function () use ($data) {
            $coupon = Coupon::create(Arr::except($data, ['brand_ids', 'product_ids']));

            $this->syncTargets($coupon, $data);

            return $coupon->refresh();
        });

        return $this->success(CouponResource::make($this->withDetails($coupon)), 'Coupon created successfully.', 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return $this->success(CouponResource::make($this->withDetails($coupon)), 'Coupon retrieved successfully.');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validated();
        $type = $data['type'] ?? $coupon->type->value;

        // A fixed coupon has no maximum discount.
        if ($type === CouponType::Fixed->value) {
            $data['max_discount'] = null;
        }

        DB::transaction(function () use ($coupon, $data) {
            $coupon->update(Arr::except($data, ['brand_ids', 'product_ids']));

            $this->syncTargets($coupon, $data);
        });

        return $this->success(CouponResource::make($this->withDetails($coupon)), 'Coupon updated successfully.');
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->redemptions()->where('status', RedemptionStatus::Reserved->value)->exists()) {
            return $this->error('This coupon has uses waiting for payment. Deactivate it instead, or wait until they end.', 409);
        }

        $coupon->delete();

        return $this->success(null, 'Coupon deleted successfully.');
    }

    /**
     * The targets follow the scope. Changing the scope clears the lists that no longer apply.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncTargets(Coupon $coupon, array $data): void
    {
        if ($coupon->scope === CouponScope::Brands) {
            if (array_key_exists('brand_ids', $data)) {
                $coupon->brands()->sync($data['brand_ids']);
            }
        } else {
            $coupon->brands()->detach();
        }

        if ($coupon->scope === CouponScope::Products) {
            if (array_key_exists('product_ids', $data)) {
                $coupon->products()->sync($data['product_ids']);
            }
        } else {
            $coupon->products()->detach();
        }
    }

    private function withDetails(Coupon $coupon): Coupon
    {
        return $coupon
            ->load(['brands:id,name,slug', 'products:id,name,slug'])
            ->loadCount([
                'redemptions as used_count' => fn ($q) => $q->whereIn('status', RedemptionStatus::counting()),
                'brands',
                'products',
            ]);
    }
}
