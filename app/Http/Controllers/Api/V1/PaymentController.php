<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function pay(Request $request, string $number): JsonResponse
    {
        $order = $this->order($request, $number);
        $payment = $this->payments->initiate($order);
        $created = $payment->wasRecentlyCreated;

        return $this->success(
            [
                'payment' => PaymentResource::make($payment),
                'attempts_used' => $this->used($order),
                'attempts_left' => $this->left($order),
            ],
            $created ? 'Payment started.' : 'Payment already started.',
            $created ? 201 : 200
        );
    }

    public function index(Request $request, string $number): JsonResponse
    {
        $order = $this->order($request, $number);

        return $this->success(
            [
                'attempts_used' => $this->used($order),
                'attempts_left' => $this->left($order),
                'payments' => PaymentResource::collection($order->payments()->reorder('id', 'desc')->get()),
            ],
            'Payments retrieved successfully.'
        );
    }

    /** Only the customer's own orders are found. Another customer's order is a 404. */
    private function order(Request $request, string $number): Order
    {
        /** @var User $user */
        $user = $request->user();

        return $user->orders()->where('number', $number)->firstOrFail();
    }

    private function used(Order $order): int
    {
        return $order->payments()->count();
    }

    private function left(Order $order): int
    {
        return max(0, PaymentService::maxAttempts() - $this->used($order));
    }
}
