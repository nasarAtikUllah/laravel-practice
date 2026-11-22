<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentRecordStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentCallbackService;
use App\Services\Payments\CallbackThrottled;
use App\Services\Payments\GatewayException;
use App\Services\Payments\InvalidWebhookException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentCallbackController extends Controller
{
    public function __construct(private readonly PaymentCallbackService $callbacks) {}

    /** The provider calls us. A wrong status makes the provider try again later. */
    public function ipn(Request $request): JsonResponse
    {
        try {
            $result = $this->callbacks->handle($request, signed: true);
        } catch (CallbackThrottled $e) {
            return response()->json(['message' => 'Too many notifications for this payment. Please try again later.', 'data' => null], 429)
                ->header('Retry-After', (string) $e->retryAfter);
        } catch (InvalidWebhookException $e) {
            Log::warning('A payment notification was refused.', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => $e->getMessage(), 'data' => null], $e->status);
        } catch (GatewayException $e) {
            report($e);

            return response()->json(['message' => 'The payment could not be checked right now. Please try again.', 'data' => null], 503);
        }

        $duplicate = $result['outcome'] === 'duplicate';

        return response()->json([
            'message' => $duplicate ? 'Notification already processed.' : 'Notification processed.',
            'data' => ['result' => $result['outcome']],
        ]);
    }

    public function paymentSuccess(Request $request): RedirectResponse
    {
        return $this->back($request);
    }

    public function paymentFail(Request $request): RedirectResponse
    {
        return $this->back($request);
    }

    public function paymentCancel(Request $request): RedirectResponse
    {
        return $this->back($request);
    }

    /** The customer comes back from the provider. Whatever happens, the customer lands on the shop. */
    private function back(Request $request): RedirectResponse
    {
        $reference = $request->input('tran_id');
        $payment = is_string($reference) && $reference !== '' && strlen($reference) <= 40
            ? Payment::query()->with('order:id,number')->where('reference', $reference)->first()
            : null;

        try {
            $payment = $this->callbacks->handle($request, signed: false)['payment'];
        } catch (Throwable $e) {
            // Nothing changed. The customer sees the state we know.
            if (! $e instanceof CallbackThrottled && ! $e instanceof InvalidWebhookException) {
                report($e);
            }
        }

        return redirect()->away($this->returnUrl($payment), 303);
    }

    private function returnUrl(?Payment $payment): string
    {
        $orderUrl = config('payments.frontend_order_url');
        $base = $payment?->order !== null && is_string($orderUrl) && trim($orderUrl) !== ''
            ? rtrim($orderUrl, '/').'/'.rawurlencode($payment->order->number)
            : (string) config('payments.return_url');

        $query = array_filter([
            'order' => $payment?->order->number,
            'result' => $this->result($payment),
        ]);

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($query);
    }

    private function result(?Payment $payment): string
    {
        if ($payment === null) {
            return 'unknown';
        }

        return match ($payment->status) {
            // Money that cannot be used for the order will be refunded.
            PaymentRecordStatus::Succeeded => $payment->review_reason === null ? 'success' : 'refund_pending',
            PaymentRecordStatus::Failed => $payment->review_reason === null ? 'failed' : 'refund_pending',
            PaymentRecordStatus::Cancelled => 'cancelled',
            PaymentRecordStatus::Expired => 'expired',
            PaymentRecordStatus::Initiated => 'pending',
        };
    }
}
