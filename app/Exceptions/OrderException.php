<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderException extends DomainException
{
    /** @param array<int, array<string, mixed>>|null $errors */
    public function __construct(string $message, public readonly int $status = 422, public readonly ?array $errors = null)
    {
        parent::__construct($message);
    }

    /** @param array<int, array<string, mixed>> $issues */
    public static function cartNotReady(array $issues): self
    {
        return new self('Your cart needs attention before checkout.', 422, $issues);
    }

    public static function cartChanged(): self
    {
        return new self('Your cart changed since you prepared it. Please review your cart and try again.', 409);
    }

    public static function itemsChanged(): self
    {
        return new self('Some items are no longer available in the quantity you chose. Please review your cart.', 409);
    }

    public static function cannotCancel(): self
    {
        return new self('This order can no longer be cancelled.', 409);
    }

    public static function paidNeedsRefund(): self
    {
        return new self('This order has already been paid. Please contact us to cancel it and get a refund.', 409);
    }

    public static function notConfirmable(): self
    {
        return new self('Only an order that is waiting for confirmation can be confirmed.', 409);
    }

    public static function notShippable(): self
    {
        return new self('Only a confirmed order can be shipped.', 409);
    }

    public static function notDeliverable(): self
    {
        return new self('Only a shipped order can be marked as delivered.', 409);
    }

    public static function paidNeedsRefundByStaff(): self
    {
        return new self('This order has already been paid. It must be refunded before it can be cancelled.', 409);
    }

    public static function onlineAmount(string $detail): self
    {
        return new self($detail.' Please choose cash on delivery.', 422);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'data' => null,
            'errors' => $this->errors,
        ], fn ($value, $key) => $key !== 'errors' || $value !== null, ARRAY_FILTER_USE_BOTH), $this->status);
    }
}
