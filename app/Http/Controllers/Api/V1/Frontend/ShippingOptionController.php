<?php

namespace App\Http\Controllers\Api\V1\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\ShippingOptionResource;
use App\Models\ShippingOption;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class ShippingOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $options = ShippingOption::query()->active()->ordered()->get();

        return response()->json([
            'message' => 'Delivery options retrieved successfully.',
            'currency' => Money::currency(),
            'data' => ShippingOptionResource::collection($options)->resolve(),
        ]);
    }
}
