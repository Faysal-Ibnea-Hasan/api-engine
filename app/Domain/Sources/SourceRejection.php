<?php

namespace App\Domain\Sources;

use RuntimeException;

/**
 * The source itself is unacceptable (snapshot → rejected). Each code has one fixed,
 * user-safe message; messages never include entry names, sizes from the archive or
 * system errors, so a hostile archive can't put text in front of users or into logs.
 */
final class SourceRejection extends RuntimeException
{
    private const MESSAGES = [
        'unsafe_path' => 'The source contains a file path that is absolute, uses "..", or has invalid characters.',
        'path_collision' => 'The source contains two files with the same path (or paths that differ only by letter case).',
        'symlink' => 'The source contains a symbolic link, which is not allowed. Replace links with real files.',
        'special_file' => 'The archive contains a device, pipe or other special file. Only regular files and folders are allowed.',
        'too_deep' => 'The source has folders nested too deeply.',
        'too_many_files' => 'The source has more files than allowed.',
        'too_large' => 'The source is larger than allowed once unpacked.',
        'empty' => 'The source contains no files that can be analyzed.',
        'unsupported_format' => 'That file is not a ZIP archive. Upload a .zip of your Laravel project.',
        'malformed_archive' => 'The ZIP archive is damaged or incomplete. Create it again and re-upload.',
        'encrypted_archive' => 'Password-protected ZIP archives are not supported. Upload an unencrypted .zip.',
        'compression_ratio' => 'The archive expands far more than a normal project would, so it was refused for safety.',
        'extraction_timeout' => 'Unpacking the archive took too long. Upload a smaller archive (leaving out vendor/ and node_modules/ helps).',
        'cancelled' => 'The import was cancelled.',
        'storage_failed' => 'The source could not be stored. Try again; if it keeps failing, contact an administrator.',
        'worker_lost' => 'The import stopped unexpectedly. Try again.',
    ];

    private function __construct(public readonly string $failureCode)
    {
        parent::__construct(self::messageFor($failureCode));
    }

    public static function because(string $code): self
    {
        return new self(array_key_exists($code, self::MESSAGES) ? $code : 'internal');
    }

    public static function unsafePath(): self
    {
        return new self('unsafe_path');
    }

    public static function pathCollision(): self
    {
        return new self('path_collision');
    }

    public static function symlink(): self
    {
        return new self('symlink');
    }

    public static function tooManyFiles(): self
    {
        return new self('too_many_files');
    }

    public static function tooLarge(): self
    {
        return new self('too_large');
    }

    public static function empty(): self
    {
        return new self('empty');
    }

    /** Fixed, user-safe message for a stored failure code. */
    public static function messageFor(string $code): string
    {
        return self::MESSAGES[$code] ?? 'The import failed. Try again; if it keeps failing, contact an administrator.';
    }
}
