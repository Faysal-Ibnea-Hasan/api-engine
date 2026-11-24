<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Passes with any Authorization header (guard() is just an object) or with a guest_id. */
class GuestOrToken
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('Authorization') && $request->header('Authorization') !== 'Bearer null' && app('auth')->guard('api')) {
            $request->merge(['user' => auth('api')->user()]);

            return $next($request);
        } elseif ($request->guest_id) {
            return $next($request);
        }

        return response()->json(['errors' => [['code' => 'auth-001']]], 401);
    }
}
