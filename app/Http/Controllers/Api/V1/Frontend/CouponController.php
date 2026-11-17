<?php

namespace App\Http\Controllers\Api\V1\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\CouponResource;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class CouponController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();

        $coupons = Coupon::query()
            ->where('is_active', true)
            // Started: no start time, or the start time has come (the start time is included).
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            // Not expired: no expiry, or the expiry is still ahead (the expiry time is not included).
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->with([
                // Only brands and products a customer can see anyway, so a draft product name never leaks.
                'brands' => fn ($q) => $q->where('brands.is_active', true)->select('brands.id', 'brands.name', 'brands.slug'),
                'products' => fn ($q) => $q->visible()->select('products.id', 'products.name', 'products.slug'),
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'message' => 'Coupons retrieved successfully.',
            'currency' => Money::currency(),
            'data' => CouponResource::collection($coupons)->resolve(),
        ]);
    }
}
