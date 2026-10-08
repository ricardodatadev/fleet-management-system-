<?php

namespace App\Support\Audit;

use Illuminate\Http\Request;

/** Origem do evento (http/console/queue) e dados da requisição para o audit trail. */
final class AuditContext
{
    public static bool $inQueueJob = false;

    public static function source(): string
    {
        if (self::$inQueueJob) {
            return 'queue';
        }

        return self::httpRequest() !== null ? 'http' : 'console';
    }

    /** A requisição HTTP real (a que passou pelo middleware RequestId), ou null. */
    public static function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = app('request');

        return $request instanceof Request && $request->attributes->has('request_id') ? $request : null;
    }
}
