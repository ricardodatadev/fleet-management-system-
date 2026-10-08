<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envelope único da API: {status, message, errors, data[, meta]}.
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return self::build('success', $message ?? __('api.success'), null, $data, null, $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message ?? __('api.created'), 201);
    }

    /** Um recurso (JsonResource) no envelope. */
    public static function item(JsonResource $resource, ?string $message = null, int $status = 200): JsonResponse
    {
        return self::success($resource->resolve(request()), $message, $status);
    }

    /**
     * Lista paginada. $resourceClass é uma subclasse de JsonResource (ex.: ApiResource).
     *
     * @param  class-string<JsonResource>|null  $resourceClass
     */
    public static function paginated(LengthAwarePaginator $paginator, ?string $resourceClass = null, ?string $message = null): JsonResponse
    {
        $items = collect($paginator->items());
        $data = $resourceClass === null
            ? $items->values()->all()
            : $items->map(fn ($item) => (new $resourceClass($item))->resolve(request()))->values()->all();

        return self::build('success', $message ?? __('api.success'), null, $data, [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ], 200);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, int $status, ?array $errors = null, mixed $data = null): JsonResponse
    {
        return self::build('error', $message, $errors, $data, null, $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validationError(array $errors, ?string $message = null): JsonResponse
    {
        return self::error($message ?? __('api.422'), 422, $errors);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, array<int, string>>|null  $errors
     */
    private static function build(string $status, string $message, ?array $errors, mixed $data, ?array $meta, int $code): JsonResponse
    {
        $body = [
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $code, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
