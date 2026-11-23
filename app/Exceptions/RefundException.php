<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundException extends DomainException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function amountUnavailable(): self
    {
        return new self('The refund amount exceeds the money received and not already refunded.');
    }

    public static function notRefundable(): self
    {
        return new self('This payment has no recorded money available to refund.', 409);
    }

    public static function cannotRetry(): self
    {
        return new self('Only a failed refund can be retried.', 409);
    }

    public static function cannotResolve(): self
    {
        return new self('Only a processing refund can be resolved.', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('This idempotency key was already used for a different refund.', 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], $this->status);
    }
}
