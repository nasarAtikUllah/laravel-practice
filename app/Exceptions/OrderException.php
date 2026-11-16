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

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'data' => null,
            'errors' => $this->errors,
        ], fn ($value, $key) => $key !== 'errors' || $value !== null, ARRAY_FILTER_USE_BOTH), $this->status);
    }
}
