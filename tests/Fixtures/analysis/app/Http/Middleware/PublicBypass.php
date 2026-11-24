<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Checks the user, except for a public prefix that skips the check. */
class PublicBypass
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/public/*')) {
            return $next($request);
        }
        abort_unless(auth()->check(), 401);

        return $next($request);
    }
}
