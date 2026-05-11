<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    protected function success(array $data = [], string $message = 'ok', int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message] + $data, $status);
    }

    protected function error(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $message, 'errors' => $errors], $status);
    }
}
