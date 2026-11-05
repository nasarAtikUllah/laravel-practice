<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RedemptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponRedemptionResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponRedemptionController extends Controller
{
    public function index(Request $request, Coupon $coupon): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(RedemptionStatus::class)],
        ]);

        $redemptions = $coupon->redemptions()
            ->with('user:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(20);

        return $this->paginated($redemptions, CouponRedemptionResource::class, 'Coupon redemptions retrieved successfully.');
    }
}
