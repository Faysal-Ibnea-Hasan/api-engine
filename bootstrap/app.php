<?php

use App\Domain\Shared\DomainError;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceWorkspaceImpersonation;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->alias(['feature' => EnsureFeatureEnabled::class]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnforceWorkspaceImpersonation::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One 404 body for "doesn't exist" and "exists in another tenant": never name the model.
        $exceptions->map(ModelNotFoundException::class, fn (ModelNotFoundException $e) => new NotFoundHttpException('Not Found', $e));

        // A body over post_max_size is refused before the session starts, so there is no
        // form error to flash: answer 413 plainly. The upload form checks the size first.
        $exceptions->render(fn (PostTooLargeException $e) => response(__('The upload is too large.'), 413));

        $exceptions->dontReport(DomainError::class);

        // GitHub App secrets (Settings → GitHub App) never go into the session's old input.
        $exceptions->dontFlash(['client_secret', 'private_key']);

        $exceptions->render(function (DomainError $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode(),
                        'message' => $e->getMessage(),
                        'correlation_id' => Context::get('correlation_id'),
                    ],
                ], $e->status());
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        });
    })->create();
