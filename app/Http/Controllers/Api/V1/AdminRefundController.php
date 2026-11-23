<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminResolveRefundRequest;
use App\Http\Resources\AdminRefundResource;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminRefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) $request->input('search'));
        $prefix = addcslashes($term, '%_\\').'%';

        $refunds = Refund::query()
            ->with(['payment:id,reference', 'order:id,number'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($term !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('reference', 'like', $prefix)
                ->orWhereHas('payment', fn ($payment) => $payment->where('reference', 'like', $prefix))
                ->orWhereHas('order', fn ($order) => $order->where('number', 'like', $prefix))))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return $this->paginated($refunds, AdminRefundResource::class, 'Refunds retrieved successfully.');
    }

    public function show(Refund $refund): JsonResponse
    {
        return $this->success(
            AdminRefundResource::make($refund->load(['payment:id,reference', 'order:id,number'])),
            'Refund retrieved successfully.'
        );
    }

    public function resolve(
        AdminResolveRefundRequest $request,
        Refund $refund,
        RefundService $refunds,
    ): JsonResponse {
        $refund = $refunds->resolve(
            $refund,
            RefundStatus::from($request->validated('status')),
            $this->user($request),
            $request->validated('resolution_note'),
            $request->validated('provider_refund_id'),
            $request->validated('failure_reason'),
        );

        return $this->success(
            AdminRefundResource::make($refund->load(['payment:id,reference', 'order:id,number'])),
            'Refund resolved successfully.'
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
