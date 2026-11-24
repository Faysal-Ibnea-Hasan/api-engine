<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Activation503
{
    public function handle(Request $request, Closure $next, $area = null)
    {
        if (! $this->activated($area)) {
            return response()->json(['message' => 'Not activated'], 503);
        }

        return $next($request);
    }

    private function activated($area): bool
    {
        return cache()->has('activated_'.$area);
    }
}
