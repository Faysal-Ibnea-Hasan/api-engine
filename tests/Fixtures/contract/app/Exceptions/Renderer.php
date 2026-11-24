<?php

namespace ContractFixture\App\Exceptions;

use ContractFixture\App\Support\Envelope;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Renderer
{
    public static function render(Throwable $e, Request $request)
    {
        if (! $request->is('api/*') || $e instanceof HttpResponseException) {
            return null;
        }
        if ($e instanceof NotFoundHttpException) {
            return response()->json(Envelope::make(config('response.default_404'), null, [['code' => 'not-found', 'message' => 'Not found']]), 404);
        }
        if ($e instanceof HttpExceptionInterface) {
            return response()->json(Envelope::make(['identical_code' => 'error_'.$e->getStatusCode(), 'message' => 'Error'], null, []), $e->getStatusCode());
        }

        return response()->json(Envelope::make(config('response.default_500'), null, [['code' => 'server-error', 'message' => config('app.debug') ? $e->getMessage() : 'Something went wrong']]), 500);
    }
}
