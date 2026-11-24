<?php

namespace Fixture\App\Http\Middleware;

use Closure;
use Fixture\App\Models\Module;
use Illuminate\Http\Request;

/** Needs a moduleId header naming an existing module: a request value, not a credential. */
class ModuleHeaderGate
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->hasHeader('moduleId')) {
            return response()->json(['errors' => [['code' => 'module']]], 403);
        }

        $module = Module::where('id', $request->header('moduleId'))->first();
        if (! $module) {
            return response()->json(['errors' => [['code' => 'module']]], 403);
        }

        return $next($request);
    }
}
