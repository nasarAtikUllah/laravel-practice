<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponException extends DomainException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /** Used for unknown, inactive, not started, expired and limit reached, so codes cannot be probed. */
    public static function invalid(): self
    {
        return new self('This coupon code is not valid.');
    }

    public static function minimumNotMet(string $amount): self
    {
        return new self("The order total must be at least {$amount} to use this coupon.");
    }

    public static function noDiscount(): self
    {
        return new self('This coupon does not give a discount on this order.');
    }

    public static function itemsUnavailable(): self
    {
        return new self('One or more items are not available.');
    }

    public static function cartTooLarge(): self
    {
        return new self('The order total is too large to use a coupon.');
    }

    public static function alreadyHasCoupon(): self
    {
        return new self('This order already has a coupon.');
    }

    public static function useEnded(): self
    {
        return new self('This coupon use has been released or has expired.', 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], $this->status);
    }
}
