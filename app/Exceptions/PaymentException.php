<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentException extends DomainException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notOnline(): self
    {
        return new self('This order is paid on delivery.', 422);
    }

    public static function notWaitingForPayment(): self
    {
        return new self('This order is not waiting for payment.', 409);
    }

    public static function holdEnded(): self
    {
        return new self('The time to pay for this order has ended. Please place a new order.', 409);
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many payment attempts for this order. Please contact us.', 409);
    }

    public static function amountTooSmall(string $minimum): self
    {
        return new self("Online payment is available for orders of {$minimum} or more.", 422);
    }

    public static function amountTooLarge(string $maximum): self
    {
        return new self("Online payment is available for orders up to {$maximum}.", 422);
    }

    public static function gatewayUnavailable(): self
    {
        return new self('Payment is not available right now. Please try again in a moment.', 503);
    }

    public static function notFound(): self
    {
        return new self('Payment not found.', 404);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], $this->status);
    }
}
