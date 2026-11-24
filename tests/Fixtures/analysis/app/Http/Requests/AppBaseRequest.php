<?php

namespace Fixture\App\Http\Requests;

use Fixture\App\Support\ApiEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

abstract class AppBaseRequest extends FormRequest
{
    protected function responseFormatter(array $config, mixed $content = null, array $errors = []): JsonResponse
    {
        return response()->json(ApiEnvelope::make($config, $content, $errors), $config['http_response_code'] ?? 422);
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = $this->responseFormatter(
            config: config('response.unprocessable_entity_422'),
            errors: ApiEnvelope::errorList($validator->errors()->getMessages()),
        );
        $payload = $response->getData(true);
        $payload['message'] = $validator->errors()->first() ?: $payload['message'];

        throw new HttpResponseException($response->setData($payload));
    }
}
