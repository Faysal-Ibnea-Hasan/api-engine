<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Models\Customer;
use Illuminate\Http\Request;

/** Verifies the bearer token against the database on every path. */
class TokenLookup
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $customer = Customer::where('auth_token', $token)->first();
        if ($customer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
