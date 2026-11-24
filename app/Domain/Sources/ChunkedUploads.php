<?php

namespace App\Domain\Sources;

use App\Contracts\Sources\SourceImportResult;
use App\Domain\Projects\ProjectError;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Resumable uploads for archives up to 10 GB. Pieces must arrive in order: each one
 * says where it starts, and only a piece starting exactly at received_bytes is accepted.
 * A dropped connection or page reload resumes from received_bytes.
 */
final class ChunkedUploads
{
    public function __construct(private readonly ZipSourceProvider $zips) {}

    public function start(User $actor, Project $project, int $totalBytes): UploadSession
    {
        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $session = new UploadSession;
        $session->forceFill([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'total_bytes' => $totalBytes,
            'received_bytes' => 0, // set explicitly: a new model doesn't carry the DB default, and the client resumes from this
            'status' => UploadSession::UPLOADING,
            'expires_at' => now()->addHours((int) config('sources.zip.upload_ttl_hours')),
        ])->save();

        return $session;
    }

    /** @param  resource  $body */
    public function append(UploadSession $session, int $offset, $body): UploadSession
    {
        return DB::transaction(function () use ($session, $offset, $body) {
            $session = UploadSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== UploadSession::UPLOADING || $session->expires_at->isPast()) {
                throw UploadError::notActive();
            }

            if ($offset !== $session->received_bytes) {
                throw UploadError::offsetMismatch();
            }

            $disk = Storage::disk('quarantine');
            $path = $disk->path($session->partPath());
            is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);

            $out = fopen($path, 'c+b') ?: throw new \RuntimeException('Could not open the upload file.');

            try {
                // Drop bytes from a piece that failed half-way: the file must end at received_bytes.
                ftruncate($out, max(0, $session->received_bytes));
                fseek($out, $session->received_bytes);

                $max = (int) config('sources.zip.chunk_bytes');
                $read = (int) stream_copy_to_stream($body, $out, $max + 1);

                $problem = match (true) {
                    $read === 0 => UploadError::emptyChunk(),
                    $read > $max => UploadError::chunkTooLarge(),
                    $offset + $read > $session->total_bytes => UploadError::beyondDeclaredSize(),
                    default => null,
                };

                if ($problem !== null) {
                    ftruncate($out, max(0, $session->received_bytes));
                    throw $problem;
                }

                fflush($out);
            } finally {
                fclose($out);
            }

            $session->forceFill(['received_bytes' => $offset + $read])->save();

            return $session;
        });
    }

    /** Idempotent: finishing twice returns the same import. */
    public function complete(User $actor, UploadSession $session): SourceImportResult
    {
        return DB::transaction(function () use ($actor, $session) {
            $session = UploadSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status === UploadSession::COMPLETED && $session->processing_run_id !== null) {
                $run = ProcessingRun::query()->findOrFail($session->processing_run_id);

                return SourceImportResult::fromRun($run, alreadyRunning: true);
            }

            if ($session->status !== UploadSession::UPLOADING || $session->expires_at->isPast()) {
                throw UploadError::notActive();
            }

            if ($session->received_bytes !== $session->total_bytes) {
                throw UploadError::incomplete();
            }

            $result = $this->zips->import($actor, $session->project, ['upload' => $session]);
            $session->forceFill(['status' => UploadSession::COMPLETED, 'processing_run_id' => $result->runId])->save();

            return $result;
        });
    }

    /** Deletes the bytes of uploads that were never finished. Returns how many. */
    public function pruneExpired(): int
    {
        $expired = UploadSession::query()->where('status', UploadSession::UPLOADING)->where('expires_at', '<', now())->get();

        foreach ($expired as $session) {
            Storage::disk('quarantine')->deleteDirectory(dirname($session->partPath()));
            $session->forceFill(['status' => UploadSession::EXPIRED])->save();
        }

        return $expired->count();
    }
}
