<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** A token field that must exist in the couriers table. */
class ValidatorExists
{
    public function handle(Request $request, Closure $next)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|exists:couriers,auth_token',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 401);
        }

        return $next($request);
    }
}
