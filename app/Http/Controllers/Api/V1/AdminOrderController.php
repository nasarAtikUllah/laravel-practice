<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminCancelOrderRequest;
use App\Http\Requests\Api\V1\OrderNoteRequest;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) $request->input('search'));

        $orders = Order::query()
            ->with('user:id,name,email')
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->input('payment_method')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
            ->when($request->filled('from'), fn ($q) => $q->where('placed_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('placed_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->when($term !== '', function ($q) use ($term) {
                $prefix = addcslashes($term, '%_\\').'%';
                $contains = $this->likePattern($term);

                // The number and the phone are matched from the start, so their indexes can be used.
                $q->where(fn ($w) => $w
                    ->where('number', 'like', $prefix)
                    ->orWhere('shipping_phone', 'like', $prefix)
                    ->orWhere('shipping_name', 'like', $contains)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $contains)->orWhere('email', 'like', $contains)));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return $this->paginated($orders, AdminOrderResource::class, 'Orders retrieved successfully.');
    }

    public function show(string $number): JsonResponse
    {
        return $this->success(AdminOrderResource::make($this->detail($this->find($number))), 'Order retrieved successfully.');
    }

    public function confirm(OrderNoteRequest $request, string $number): JsonResponse
    {
        $order = $this->orders->confirm($this->find($number), $this->user($request), $request->input('note'));

        return $this->success(AdminOrderResource::make($this->detail($order)), 'Order confirmed successfully.');
    }

    public function ship(OrderNoteRequest $request, string $number): JsonResponse
    {
        $order = $this->orders->ship($this->find($number), $this->user($request), $request->input('note'));

        return $this->success(AdminOrderResource::make($this->detail($order)), 'Order marked as shipped.');
    }

    public function deliver(OrderNoteRequest $request, string $number): JsonResponse
    {
        $order = $this->orders->deliver($this->find($number), $this->user($request), $request->input('note'));

        return $this->success(AdminOrderResource::make($this->detail($order)), 'Order marked as delivered.');
    }

    public function cancel(AdminCancelOrderRequest $request, string $number): JsonResponse
    {
        $order = $this->find($number);

        if (
            $order->payment_status === PaymentStatus::Paid
            && $order->status->canMoveTo(OrderStatus::Cancelled)
            && ! $request->exists('include_delivery_fee')
        ) {
            $request->validate(['include_delivery_fee' => ['required', 'boolean']]);
        }

        $order = $this->orders->cancel(
            $order,
            $this->user($request),
            (string) $request->input('reason'),
            byStaff: true,
            includeDeliveryFee: $request->boolean('include_delivery_fee'),
        );

        return $this->success(AdminOrderResource::make($this->detail($order)), 'Order cancelled successfully.');
    }

    private function find(string $number): Order
    {
        return Order::query()->where('number', $number)->firstOrFail();
    }

    private function detail(Order $order): Order
    {
        return $order->load(['items', 'statusHistory.user:id,name', 'user:id,name,email', 'canceller:id,name', 'payments.refunds']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
