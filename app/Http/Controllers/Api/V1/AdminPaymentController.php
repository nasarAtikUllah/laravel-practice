<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentResource;
use App\Models\Payment;
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
            ->with(['order.user:id,name,email', 'events' => fn ($q) => $q->orderBy('id')])
            ->firstOrFail();

        return $this->success(AdminPaymentResource::make($payment), 'Payment retrieved successfully.');
    }
}
