<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags every request with a correlation ID. Context adds it to every log
 * entry and carries it into queued jobs dispatched during the request.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header(self::HEADER);

        // Only trust a caller's ID when it is a UUID, so logs can't be injected into.
        if (! is_string($id) || ! Str::isUuid($id)) {
            $id = (string) Str::uuid();
        }

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
