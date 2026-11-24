<?php

namespace Fixture\App\Support;

final class ApiEnvelope
{
    public static function make(array $config, mixed $content = null, array $errors = []): array
    {
        return [
            'identical_code' => $config['identical_code'] ?? null,
            'message' => $config['message'] ?? null,
            'content' => $content,
            'errors' => $errors,
        ];
    }

    public static function errorList(array $errors): array
    {
        return $errors;
    }
}
