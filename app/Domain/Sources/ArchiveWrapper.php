<?php

namespace App\Domain\Sources;

/**
 * The one generated folder a provider archive wraps everything in (GitHub: "owner-repo-<sha7>/").
 * Every entry must sit in that same folder; anything else means the archive isn't what the
 * provider produces, so it is refused rather than merged.
 */
final class ArchiveWrapper
{
    private ?string $folder = null;

    /** The path without the wrapper folder; null for the wrapper folder itself. */
    public function strip(string $path, bool $isDir): ?string
    {
        $slash = strpos($path, '/');
        $first = $slash === false ? $path : substr($path, 0, $slash);

        if ($slash === false && ! $isDir) {
            throw SourceRejection::because('malformed_archive'); // a file next to the wrapper folder
        }

        $this->folder ??= $first;
        if ($first !== $this->folder) {
            throw SourceRejection::because('malformed_archive'); // a second top-level folder
        }

        return $slash === false ? null : substr($path, $slash + 1);
    }
}
