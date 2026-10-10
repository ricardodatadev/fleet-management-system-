<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gera (uuid v4) ou propaga um X-Request-Id válido (UUID) e o devolve no response.
 * Disponível em $request->attributes->get('request_id') e no contexto de logs.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER);
        $id = is_string($incoming) && Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
