<?php

namespace App\Domain\Sources;

use App\Contracts\Sources\SourceImportResult;
use App\Contracts\Sources\SourceProvider;
use App\Models\Project;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * P04. Hands a fully received upload to the importer: the archive is moved (never
 * copied, never opened here) into the run's quarantine folder, and the worker unpacks
 * and validates it. Nothing anywhere executes its contents.
 */
final class ZipSourceProvider implements SourceProvider
{
    public function __construct(private readonly SnapshotImporter $importer) {}

    /** @param  array{upload?: UploadSession}  $configuration */
    public function import(User $actor, Project $project, array $configuration): SourceImportResult
    {
        $upload = $configuration['upload'] ?? null;

        if (! $upload instanceof UploadSession || $upload->received_bytes !== $upload->total_bytes || $upload->project_id !== $project->id) {
            throw new InvalidArgumentException('A completed upload for this project is required.');
        }

        // The upload session is the fingerprint: no need to hash 10 GB inside a request.
        // Identical content still dedupes to one snapshot through the manifest hash.
        [$run, $alreadyRunning] = $this->importer->begin($actor, $project, SourceType::Zip, 'upload:'.$upload->id, function (Filesystem $quarantine, string $dir) use ($upload) {
            $quarantine->move($upload->partPath(), dirname($dir).'/'.SnapshotImporter::ARCHIVE);
            $quarantine->deleteDirectory(dirname($upload->partPath()));
        });

        return SourceImportResult::fromRun($run, $alreadyRunning);
    }
}
