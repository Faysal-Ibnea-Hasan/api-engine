<?php

namespace Fixture\App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class AppAuthenticate extends Middleware
{
    protected function redirectTo($request)
    {
        return $request->expectsJson() ? null : route('login');
    }
}
