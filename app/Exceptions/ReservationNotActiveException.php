<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This stock reservation has been released or has expired.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'data' => null], 409);
    }
}
