<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Models\Merchant;
use Fixture\App\Models\MerchantStaff;
use Illuminate\Http\Request;

/** Requires a token, but looks it up only for two account types; any other type falls through. */
class BranchOnlyLookup
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (strlen($token) < 1) {
            return response()->json(['errors' => [['code' => 'auth']]], 401);
        }
        if (! $request->hasHeader('accountType')) {
            return response()->json(['errors' => [['code' => 'type']]], 403);
        }

        $type = $request->header('accountType');
        if ($type == 'owner') {
            $account = Merchant::where('auth_token', $token)->first();
            if (! isset($account)) {
                return response()->json(['errors' => [['code' => 'auth']]], 401);
            }
        } elseif ($type == 'staff') {
            $account = MerchantStaff::where('auth_token', $token)->first();
            if (! isset($account)) {
                return response()->json(['errors' => [['code' => 'auth']]], 401);
            }
        }

        return $next($request);
    }
}
