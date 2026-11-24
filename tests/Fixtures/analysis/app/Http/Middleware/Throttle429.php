<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class Throttle429
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'limit:'.($request->user()?->id ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['message' => 'Too many requests.'], 429);
        }
        RateLimiter::hit($key);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', '6');

        return $response;
    }
}
