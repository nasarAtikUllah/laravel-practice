<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CouponType;
use App\Enums\RedemptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCouponRequest;
use App\Http\Requests\Api\V1\UpdateCouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'state' => ['nullable', Rule::in(['active', 'inactive', 'scheduled', 'expired'])],
            'type' => ['nullable', Rule::enum(CouponType::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $state = $request->input('state');
        $now = now();

        $coupons = Coupon::query()
            ->withCount(['redemptions as used_count' => fn ($q) => $q->whereIn('status', RedemptionStatus::counting())])
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%'.strtoupper(trim((string) $request->input('search'))).'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
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
        $coupon = Coupon::create($request->validated())->refresh();

        return $this->success(CouponResource::make($this->withUsed($coupon)), 'Coupon created successfully.', 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return $this->success(CouponResource::make($this->withUsed($coupon)), 'Coupon retrieved successfully.');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validated();
        $type = $data['type'] ?? $coupon->type->value;

        // A fixed coupon has no maximum discount.
        if ($type === CouponType::Fixed->value) {
            $data['max_discount'] = null;
        }

        $coupon->update($data);

        return $this->success(CouponResource::make($this->withUsed($coupon)), 'Coupon updated successfully.');
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->redemptions()->where('status', RedemptionStatus::Reserved->value)->exists()) {
            return $this->error('This coupon has uses waiting for payment. Deactivate it instead, or wait until they end.', 409);
        }

        $coupon->delete();

        return $this->success(null, 'Coupon deleted successfully.');
    }

    private function withUsed(Coupon $coupon): Coupon
    {
        return $coupon->loadCount(['redemptions as used_count' => fn ($q) => $q->whereIn('status', RedemptionStatus::counting())]);
    }
}
