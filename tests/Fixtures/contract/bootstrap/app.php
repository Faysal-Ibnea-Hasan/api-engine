<?php

use ContractFixture\App\Exceptions\Renderer;
use ContractFixture\App\Http\Middleware\EnforceEnvelope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [EnforceEnvelope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            return Renderer::render($e, $request);
        });
    })
    ->create();
