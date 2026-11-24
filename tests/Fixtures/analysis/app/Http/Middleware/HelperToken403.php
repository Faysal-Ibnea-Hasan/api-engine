<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Support\TokenRegistry;
use Illuminate\Http\Request;

/** The token is checked inside a helper this analyzer can't read, and failure is a 403. */
class HelperToken403
{
    public function handle(Request $request, Closure $next)
    {
        $account = $this->resolve($request);
        if (! $account) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    private function resolve(Request $request)
    {
        return TokenRegistry::lookup($request->bearerToken());
    }
}
