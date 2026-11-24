<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Support\Gatekeeper;
use Illuminate\Http\Request;

/** Answers 401, but the decision is made by code outside this class. */
class OpaqueGate
{
    public function handle(Request $request, Closure $next)
    {
        if (! Gatekeeper::allows($request)) {
            return response()->json([], 401);
        }

        return $next($request);
    }
}
