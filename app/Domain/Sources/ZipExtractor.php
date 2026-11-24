<?php

namespace App\Domain\Sources;

use ZipArchive;

/**
 * Unpacks an untrusted ZIP into a fresh, disposable folder without ever executing it.
 *
 * Every entry is checked from the central directory before anything is written, and
 * read back by index (never by name: a ZIP can hold two entries with one name).
 * Excluded entries (secrets, vendor/, node_modules/, nested archives, oversized files)
 * are never written; they are returned so the manifest can list them.
 */
final class ZipExtractor
{
    private const NESTED_ARCHIVE = '/\.(zip|jar|war|phar|tar|tgz|gz|bz2|xz|7z|rar)$/';

    private const ONE_MB = 1048576;

    /** @return list<ManifestEntry> excluded entries */
    public function extract(string $archive, string $destination): array
    {
        $this->assertZipSignature($archive);

        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw SourceRejection::because('malformed_archive');
        }

        try {
            return $this->extractEntries($zip, $destination);
        } finally {
            $zip->close();
        }
    }

    /** @return list<ManifestEntry> */
    private function extractEntries(ZipArchive $zip, string $destination): array
    {
        $limits = (array) config('sources.zip');
        $maxPath = (int) config('sources.max_path_length');
        $maxFile = (int) config('sources.max_file_bytes');
        $maxExtracted = (int) config('sources.max_total_bytes');
        $deadline = microtime(true) + (int) $limits['max_seconds'];
        $sourceRoots = $this->laravelRoots($zip, $maxPath, $deadline);

        if ($zip->numFiles > (int) $limits['max_entries']) {
            throw SourceRejection::tooManyFiles();
        }

        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new \RuntimeException('Could not create the extraction folder.');
        }
        $root = (string) realpath($destination);

        $excluded = [];
        $bulk = [];      // excluded folder → bytes/reason (one manifest entry per folder, not per file)
        $seen = [];      // folded file path → true
        $dirs = [];      // folded folder path → folder path as first seen
        $extracted = 0;
        $written = 0;
        $maxFiles = (int) config('sources.max_files');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (microtime(true) > $deadline) {
                throw SourceRejection::because('extraction_timeout');
            }

            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw SourceRejection::because('malformed_archive');
            }

            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw SourceRejection::because('encrypted_archive');
            }

            $name = (string) $stat['name'];
            $isDir = str_ends_with($name, '/');
            $path = SourcePath::normalize($name, $maxPath); // rejects zip-slip, absolute and NUL paths, dirs included

            if (substr_count($path, '/') + 1 > (int) $limits['max_depth']) {
                throw SourceRejection::because('too_deep');
            }

            if ($isDir) {
                continue;
            }

            $size = (int) $stat['size'];

            // Ignored folders are never written: one manifest entry per folder, no per-file tracking.
            $bulkExclusion = FileClassifier::bulkExclusion($path, $sourceRoots);
            if ($bulkExclusion !== null) {
                $folder = $bulkExclusion['path'];
                $bulk[$folder] ??= ['bytes' => 0, 'reason' => $bulkExclusion['reason']];
                $bulk[$folder]['bytes'] += $size;

                continue;
            }

            // Links (e.g. Laravel's public/storage) and device/pipe entries are skipped, never recreated:
            // only real files are ever written, so a link can't point anything outside the folder.
            $special = $this->specialType($zip, $i);
            if ($special !== null) {
                $excluded[] = new ManifestEntry($path, $size, null, null, $special);

                continue;
            }

            // All clashes are caught here, before writing, so Linux and macOS behave the same:
            // same file twice, a file where a folder is (or vice versa), folders differing only by case.
            $folded = mb_strtolower($path);
            if (isset($seen[$folded]) || isset($dirs[$folded])) {
                throw SourceRejection::pathCollision();
            }
            $seen[$folded] = true;

            for ($dir = dirname($path); $dir !== '.'; $dir = dirname($dir)) {
                $foldedDir = mb_strtolower($dir);

                if (isset($seen[$foldedDir]) || (isset($dirs[$foldedDir]) && $dirs[$foldedDir] !== $dir)) {
                    throw SourceRejection::pathCollision();
                }
                $dirs[$foldedDir] = $dir;
            }

            // Cap files and folders created on disk too, not just bytes: empty files still cost inodes.
            if (count($dirs) > $maxFiles) {
                throw SourceRejection::tooManyFiles();
            }

            $reason = FileClassifier::exclusionReason($path, $size, $maxFile)
                ?? (preg_match(self::NESTED_ARCHIVE, strtolower($path)) === 1 ? 'nested_archive' : null);

            if ($reason !== null) {
                $excluded[] = new ManifestEntry($path, $size, null, FileClassifier::language($path), $reason);

                continue;
            }

            $compressed = (int) $stat['comp_size'];
            if ($size > self::ONE_MB && ($compressed === 0 || $size / $compressed > (int) $limits['max_ratio'])) {
                throw SourceRejection::because('compression_ratio');
            }

            if (++$written > $maxFiles) {
                throw SourceRejection::tooManyFiles();
            }

            $extracted += $this->writeEntry($zip, $i, $root, $path, $size, $maxExtracted - $extracted);
        }

        foreach ($bulk as $folder => $details) {
            $excluded[] = new ManifestEntry($folder.'/', $details['bytes'], null, null, $details['reason']);
        }

        return $excluded;
    }

    /**
     * Find folders containing both artisan and composer.json. Configured ignored
     * directories are relative to these roots, so a wrapper named "public" is kept.
     *
     * @return list<string>
     */
    private function laravelRoots(ZipArchive $zip, int $maxPath, float $deadline): array
    {
        $markers = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (microtime(true) > $deadline) {
                throw SourceRejection::because('extraction_timeout');
            }

            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw SourceRejection::because('malformed_archive');
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $base = basename($name);
            if ($base !== 'artisan' && $base !== 'composer.json') {
                continue;
            }

            $path = SourcePath::normalize($name, $maxPath);
            $root = dirname($path);
            $markers[$root][$base] = true;
        }

        return array_keys(array_filter(
            $markers,
            fn (array $found): bool => isset($found['artisan'], $found['composer.json']),
        ));
    }

    private function assertZipSignature(string $archive): void
    {
        $handle = @fopen($archive, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        $handle !== false && fclose($handle);

        // Local file header, or the end-of-central-directory record of an empty ZIP.
        if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") {
            throw SourceRejection::because('unsupported_format');
        }
    }

    /**
     * 'symlink' or 'special_file' for entries that aren't regular files or folders, else null.
     * Unix file-type bits are only meaningful when the entry says it was made on Unix.
     */
    private function specialType(ZipArchive $zip, int $index): ?string
    {
        $opsys = 0;
        $attr = 0;
        $zip->getExternalAttributesIndex($index, $opsys, $attr);

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return null;
        }

        $type = ($attr >> 16) & 0170000;

        return match (true) {
            $type === 0120000 => 'symlink',
            $type !== 0 && $type !== 0100000 && $type !== 0040000 => 'special_file',
            default => null,
        };
    }

    /** Streams one entry to disk, counting real bytes: headers can lie. Returns bytes written. */
    private function writeEntry(ZipArchive $zip, int $index, string $root, string $path, int $declaredSize, int $budget): int
    {
        $target = $root.'/'.$path;
        $dir = dirname($target);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create a folder while extracting.');
        }

        // Canonical containment check: the real parent folder must sit inside the extraction root.
        $realDir = realpath($dir);
        if ($realDir === false || ($realDir !== $root && ! str_starts_with($realDir.'/', $root.'/'))) {
            throw SourceRejection::unsafePath();
        }

        $in = $zip->getStreamIndex($index);
        $out = @fopen($target, 'xb'); // 'x': never overwrite an existing file
        if ($in === false || $out === false) {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
            if ($in === false) {
                throw SourceRejection::because('malformed_archive');
            }

            throw file_exists($target) ? SourceRejection::pathCollision() : new \RuntimeException('Could not create an extracted file.');
        }

        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw SourceRejection::because('malformed_archive');
                }

                $written += strlen($chunk);
                if ($written > $declaredSize) {
                    throw SourceRejection::because('malformed_archive'); // more data than the header claimed
                }
                if ($written > $budget) {
                    throw SourceRejection::tooLarge();
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($written !== $declaredSize) {
            throw SourceRejection::because('malformed_archive');
        }

        chmod($target, 0644); // never keep execute bits from the archive

        return $written;
    }
}
