<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartException extends DomainException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function itemUnavailable(): self
    {
        return new self('This item is not available.');
    }

    public static function notEnoughStock(): self
    {
        return new self('Not enough stock for this quantity.');
    }

    public static function quantityTooHigh(int $max): self
    {
        return new self("You can buy at most {$max} of one item.");
    }

    public static function tooManyLines(int $max): self
    {
        return new self("The cart can have at most {$max} different items.");
    }

    public static function cartEmpty(): self
    {
        return new self('Your cart is empty.');
    }

    public static function itemNotInCart(): self
    {
        return new self('This item is not in your cart.', 404);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], $this->status);
    }

    public static function shippingUnavailable(): self
    {
        return new self('This delivery option is not available.');
    }

    public static function shippingMinimum(string $amount): self
    {
        return new self("This delivery option is available for orders of {$amount} or more.");
    }
}
