<?php

namespace ContractFixture\App\Http\Controllers;

use ContractFixture\App\Support\Envelope;
use Illuminate\Http\JsonResponse;

class BaseApiController
{
    protected function responseFormatter($config, $content = null, $errors = []): JsonResponse
    {
        $config = (array) $config;

        return response()->json(Envelope::make($config, $content, $errors ?? []), $config['http_response_code'] ?? 200);
    }
}
