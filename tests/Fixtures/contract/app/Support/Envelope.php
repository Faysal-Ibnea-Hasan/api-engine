<?php

namespace ContractFixture\App\Support;

final class Envelope
{
    public const KEYS = ['identical_code', 'message', 'content', 'errors'];

    public static function make(array $config, mixed $content = null, array $errors = []): array
    {
        return [
            'identical_code' => $config['identical_code'] ?? null,
            'message' => isset($config['message']) ? __($config['message']) : null,
            'content' => $content,
            'errors' => $errors,
        ];
    }

    public static function isEnvelope(mixed $payload): bool
    {
        if (! is_array($payload) || array_is_list($payload)) {
            return false;
        }
        $keys = array_keys($payload);
        sort($keys);
        $want = self::KEYS;
        sort($want);

        return $keys === $want;
    }

    public static function errorList(array $errors): array
    {
        $list = [];
        foreach ($errors as $field => $messages) {
            $list[] = ['code' => is_string($field) ? $field : 'error', 'message' => is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages];
        }

        return $list;
    }
}
