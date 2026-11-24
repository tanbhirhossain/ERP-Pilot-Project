<?php

namespace Modules\HRM\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Single JSON envelope for the whole mobile API (v1).
 *
 * Success: {success:true, message, data, meta?}
 * Error:   {success:false, message, data:null, errors?}
 *
 * Every controller returns through here so the apps parse one shape, whatever
 * module answers.
 */
class ApiResponse
{
    public static function ok(mixed $data = null, string $message = 'OK', array $meta = [], int $code = 200): JsonResponse
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $code);
    }

    public static function error(string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message, 'data' => null];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    public static function paginated(LengthAwarePaginator $paginator, string $message = 'OK', array $extraMeta = []): JsonResponse
    {
        return self::ok($paginator->items(), $message, array_merge([
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ], $extraMeta));
    }
}
