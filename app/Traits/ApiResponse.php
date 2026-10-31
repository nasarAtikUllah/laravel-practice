<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $resource, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $resource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
