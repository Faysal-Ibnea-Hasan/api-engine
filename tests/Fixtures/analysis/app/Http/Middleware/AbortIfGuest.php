<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AbortIfGuest
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(! $request->user(), 401);

        return $next($request);
    }
}
