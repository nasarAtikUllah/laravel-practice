<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStockException extends DomainException
{
    public function __construct(public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Not enough available stock. Available: {$available}, requested: {$requested}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], 422);
    }
}
