<?php

namespace App\Domain\Sources;

/**
 * Decides which files are kept and what language they are. Excluded files are
 * listed in the manifest but their content is never stored: secrets and bulk.
 */
final class FileClassifier
{
    /** Tool folders: matched in any case (macOS/Windows zips vary). */
    private const EXCLUDED_DIRS = ['.git', '.idea', '.vscode', '__macosx'];

    /**
     * Dependency folders: matched exactly. Composer and npm always write them lowercase, while
     * app code often has its own "Vendor" folders (e.g. Controllers/Api/V1/Vendor) that must be kept.
     */
    private const DEPENDENCY_DIRS = ['vendor', 'node_modules'];

    private static function directoryExclusionReason(string $dir): ?string
    {
        if (in_array($dir, self::DEPENDENCY_DIRS, true) || in_array(strtolower($dir), self::EXCLUDED_DIRS, true)) {
            return 'dependency_or_vcs';
        }

        return null;
    }

    /** @return string|null exclusion reason, or null to keep the file */
    public static function exclusionReason(string $path, int $bytes, int $maxFileBytes): ?string
    {
        $segments = explode('/', $path);
        $name = strtolower(end($segments));

        foreach (array_slice($segments, 0, -1) as $dir) {
            if (($reason = self::directoryExclusionReason($dir)) !== null) {
                return $reason;
            }
        }

        if (self::isSecret($path, $name)) {
            return 'secret';
        }

        $lower = strtolower('/'.$path);

        if (str_contains($lower, '/storage/logs/') || str_ends_with($name, '.log')) {
            return 'log';
        }

        if (str_contains($lower, '/storage/framework/sessions/') || str_contains($lower, '/storage/framework/cache/')) {
            return 'runtime_data';
        }

        if (preg_match('/\.(sqlite|sqlite3|db)$/', $name) === 1) {
            return 'database';
        }

        // Media and executables never help API analysis; skip them before they cost disk.
        if (preg_match('/\.(png|jpe?g|gif|webp|avif|bmp|tiff?|ico|heic|psd|ai|sketch|fig|mp[34]|m4a|mov|avi|mkv|webm|wav|flac|ogg|pdf|docx?|xlsx?|pptx?|woff2?|ttf|otf|eot|exe|dll|so|dylib|bin|iso|dmg|apk|ipa|class|o|a)$/', $name) === 1) {
            return 'binary';
        }

        if ($bytes > $maxFileBytes) {
            return 'too_large';
        }

        return null;
    }

    private static function isSecret(string $path, string $name): bool
    {
        if ($name === '.env.example') {
            return false;
        }

        // shortcut: a deny-list of common secret files; P15 hardening may add content scanning.
        return $name === '.env'
            || str_starts_with($name, '.env.')
            || str_ends_with($name, '.env')
            || in_array($name, ['auth.json', 'credentials.json', '.npmrc', '.netrc', '.pgpass', '.htpasswd', '.git-credentials', 'id_rsa', 'id_ecdsa', 'id_ed25519', 'id_dsa'], true)
            || str_contains(strtolower('/'.$path), '/.aws/')
            || preg_match('/^service-account.*\.json$/', $name) === 1
            || preg_match('/\.(pem|key|p12|pfx|jks|keystore)$/', $name) === 1
            || preg_match('#(^|/)storage/oauth-[^/]*\.key$#', strtolower($path)) === 1;
    }

    /**
     * The excluded folder a path sits in and its reason, or null.
     *
     * @param  list<string>  $sourceRoots
     * @return array{path: string, reason: string}|null
     */
    public static function bulkExclusion(string $path, array $sourceRoots = []): ?array
    {
        $segments = explode('/', $path);

        foreach (array_slice($segments, 0, -1) as $i => $dir) {
            if (($reason = self::directoryExclusionReason($dir)) !== null) {
                return [
                    'path' => implode('/', array_slice($segments, 0, $i + 1)),
                    'reason' => $reason,
                ];
            }

            $ignored = app()->bound('config')
                ? (array) config('sources.ignored_directories')
                : ['public'];
            $parent = $i === 0 ? '.' : implode('/', array_slice($segments, 0, $i));

            if (in_array($parent, $sourceRoots, true) && in_array($dir, $ignored, true)) {
                return [
                    'path' => implode('/', array_slice($segments, 0, $i + 1)),
                    'reason' => 'ignored_directory',
                ];
            }
        }

        return null;
    }

    /**
     * The excluded folder a path sits in (e.g. "myapp/vendor"), or null.
     *
     * @param  list<string>  $sourceRoots
     */
    public static function bulkFolder(string $path, array $sourceRoots = []): ?string
    {
        return self::bulkExclusion($path, $sourceRoots)['path'] ?? null;
    }

    /** True when the first bytes contain a NUL: treated as binary and not stored. */
    public static function looksBinary(string $head): bool
    {
        return str_contains($head, "\0");
    }

    public static function language(string $path): ?string
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_ends_with($name, '.blade.php') => 'blade',
            str_ends_with($name, '.php') || $name === 'artisan' => 'php',
            str_ends_with($name, '.json') => 'json',
            str_ends_with($name, '.js') || str_ends_with($name, '.mjs') => 'javascript',
            str_ends_with($name, '.ts') || str_ends_with($name, '.tsx') => 'typescript',
            str_ends_with($name, '.yaml') || str_ends_with($name, '.yml') => 'yaml',
            str_ends_with($name, '.xml') => 'xml',
            str_ends_with($name, '.md') => 'markdown',
            default => null,
        };
    }
}
