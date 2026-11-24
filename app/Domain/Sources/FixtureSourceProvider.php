<?php

namespace App\Domain\Sources;

use App\Contracts\Sources\SourceImportResult;
use App\Contracts\Sources\SourceProvider;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Imports a controlled fixture from tests/Fixtures/sources (P03 walkthrough and tests).
 * Local/testing only, and only fixture names from the allowlist, so no path is ever taken from input.
 */
final class FixtureSourceProvider implements SourceProvider
{
    public const FIXTURES = ['laravel-mini'];

    public function __construct(private readonly SnapshotImporter $importer) {}

    /** @param  array{fixture?: string}  $configuration */
    public function import(User $actor, Project $project, array $configuration): SourceImportResult
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new InvalidArgumentException('Fixture imports are only available in local and testing environments.');
        }

        $name = $configuration['fixture'] ?? '';

        if (! in_array($name, self::FIXTURES, true)) {
            throw new InvalidArgumentException('Unknown fixture.');
        }

        $root = config('sources.fixtures_path').'/'.$name;
        $fingerprint = 'fixture:'.$name.':'.$this->contentHash($root);

        [$run, $alreadyRunning] = $this->importer->begin($actor, $project, SourceType::Fixture, $fingerprint, function (Filesystem $quarantine, string $dir) use ($root) {
            foreach (File::allFiles($root, hidden: true) as $file) {
                $quarantine->put($dir.'/'.$file->getRelativePathname(), $file->getContents());
            }
        });

        return SourceImportResult::fromRun($run, $alreadyRunning);
    }

    private function contentHash(string $root): string
    {
        $parts = [];

        foreach (File::allFiles($root, hidden: true) as $file) {
            $parts[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($parts);

        return hash('sha256', (string) json_encode($parts));
    }
}
