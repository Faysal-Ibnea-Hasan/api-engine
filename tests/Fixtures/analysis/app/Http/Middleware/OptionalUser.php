<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Attaches the user when there is one; guests pass too. Must never read as "required". */
class OptionalUser
{
    public function handle(Request $request, Closure $next)
    {
        if (auth('api')->check()) {
            $request->merge(['user' => auth('api')->user()]);
        }

        return $next($request);
    }
}
