<?php

namespace ContractFixture\App\Http\Middleware;

use Closure;
use ContractFixture\App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnforceEnvelope
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse) {
            return $response;
        }
        $payload = $response->getData(true);
        if (Envelope::isEnvelope($payload)) {
            return $response;
        }

        return $response->setData(['identical_code' => 'default_200', 'message' => 'Successfully fetched', 'content' => $payload, 'errors' => []]);
    }
}
