<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CouponQuoteRequest;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;

class CouponQuoteController extends Controller
{
    public function __invoke(CouponQuoteRequest $request, CouponService $coupons): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $quote = $coupons->quote((string) $request->input('code'), $request->validated('items'), $user);

        return $this->success($quote, 'Coupon is valid for this order.');
    }
}
