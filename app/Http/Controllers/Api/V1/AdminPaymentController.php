<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminRefundRequest;
use App\Http\Resources\AdminPaymentResource;
use App\Http\Resources\AdminRefundResource;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PaymentRecordStatus::class)],
            'provider' => ['nullable', 'string', 'max:30'],
            'needs_review' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) $request->input('search'));

        $payments = Payment::query()
            ->with(['order:id,number,user_id,status,payment_status', 'order.user:id,name,email'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->input('provider')))
            ->when($request->filled('needs_review'), fn ($q) => $request->boolean('needs_review')
                ? $q->whereNotNull('review_reason')
                : $q->whereNull('review_reason'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->when($term !== '', function ($q) use ($term) {
                $prefix = addcslashes($term, '%_\\').'%';
                $contains = $this->likePattern($term);

                // References are matched from the start, so their indexes can be used.
                $q->where(fn ($w) => $w
                    ->where('reference', 'like', $prefix)
                    ->orWhere('provider_reference', 'like', $prefix)
                    ->orWhereHas('order', fn ($o) => $o
                        ->where('number', 'like', $prefix)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $contains)->orWhere('email', 'like', $contains))));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return $this->paginated($payments, AdminPaymentResource::class, 'Payments retrieved successfully.');
    }

    public function show(string $reference): JsonResponse
    {
        $payment = Payment::query()
            ->where('reference', $reference)
            ->with(['order.user:id,name,email', 'events' => fn ($q) => $q->orderBy('id'), 'refunds'])
            ->firstOrFail();

        return $this->success(AdminPaymentResource::make($payment), 'Payment retrieved successfully.');
    }

    public function refund(AdminRefundRequest $request, string $reference, RefundService $refunds): JsonResponse
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();

        $refund = $refunds->request(
            $payment,
            Money::cents($request->validated('amount')),
            $request->validated('reason'),
            $this->user($request),
            $request->validated('idempotency_key'),
        );

        return $this->success(
            AdminRefundResource::make($refund->load(['payment', 'order'])),
            $refund->wasRecentlyCreated ? 'Refund requested successfully.' : 'Refund request already exists.',
            $refund->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function retryRefund(Refund $refund, RefundService $refunds): JsonResponse
    {
        $refund = $refunds->retry($refund);

        return $this->success(
            AdminRefundResource::make($refund->load(['payment', 'order'])),
            'Refund retry requested successfully.',
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
