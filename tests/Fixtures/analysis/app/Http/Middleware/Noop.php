<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Noop
{
    public function handle(Request $request, Closure $next)
    {
        // if ($request->hasHeader('X-Client')) { return response()->json([], 404); }
        return $next($request);
    }
}
