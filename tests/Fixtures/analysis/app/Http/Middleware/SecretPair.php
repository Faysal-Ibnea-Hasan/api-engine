<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Models\ApiToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer key + secret header, compared with hash_equals; rejects through a helper method. */
class SecretPair
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken();
        $secret = $request->header('X-Api-Secret');
        if (! $key || ! $secret) {
            return $this->unauthorized();
        }

        $token = ApiToken::where('api_key', $key)->where('active', true)->first();
        if (! $token || ! hash_equals($token->secret_hash, hash('sha256', $secret))) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    protected function unauthorized(): Response
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
