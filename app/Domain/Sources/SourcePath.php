<?php

namespace App\Domain\Sources;

use Normalizer;

/**
 * Turns an untrusted relative path from an archive or repository into the one
 * canonical form stored in the manifest, or rejects it. Paths are only ever
 * manifest data: file content is stored by hash, so a path never reaches the filesystem.
 */
final class SourcePath
{
    public static function normalize(string $raw, int $maxLength): string
    {
        if ($raw === '' || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw SourceRejection::unsafePath();
        }

        if (str_contains($raw, '\\') || str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:/', $raw) === 1) {
            throw SourceRejection::unsafePath();
        }

        // macOS archives often carry NFD names; store one canonical form.
        $path = Normalizer::normalize($raw, Normalizer::FORM_C);

        if ($path === false) {
            throw SourceRejection::unsafePath();
        }

        $segments = explode('/', rtrim($path, '/'));

        foreach ($segments as $segment) {
            // 255 bytes is the per-name limit on ext4 and most filesystems.
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw SourceRejection::unsafePath();
            }
        }

        $path = implode('/', $segments);

        if (strlen($path) > $maxLength) {
            throw SourceRejection::unsafePath();
        }

        return $path;
    }
}
