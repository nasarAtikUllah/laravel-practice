<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = $this->user($request)->orders()
            ->withCount('items')
            ->with(['refunds:id,order_id,reference,amount,status,reason,created_at,processed_at'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        return $this->paginated($orders, OrderResource::class, 'Orders retrieved successfully.');
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $data = $request->validated();
        $key = (string) $data['idempotency_key'];
        unset($data['idempotency_key']);

        $order = $this->orders->checkout($this->user($request), $data, $key);

        // The same key again gives the first order back, with 200.
        $created = $order->wasRecentlyCreated;

        return $this->success(
            OrderResource::make($order),
            $created ? 'Order placed successfully.' : 'Order already placed.',
            $created ? 201 : 200
        );
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return $this->success(
            OrderResource::make($this->find($request, $number)),
            'Order retrieved successfully.'
        );
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $order = $this->orders->cancel(
            $this->find($request, $number),
            $this->user($request),
            $request->input('reason')
        );

        return $this->success(
            OrderResource::make($order->load(['items', 'statusHistory'])),
            'Order cancelled successfully.'
        );
    }

    /** Only the customer's own orders are found. Another customer's order is a 404. */
    private function find(Request $request, string $number): Order
    {
        return $this->user($request)->orders()
            ->where('number', $number)
            ->with(['items', 'statusHistory', 'refunds:id,order_id,reference,amount,status,reason,created_at,processed_at'])
            ->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
