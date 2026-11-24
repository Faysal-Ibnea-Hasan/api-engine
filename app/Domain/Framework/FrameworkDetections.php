<?php

namespace App\Domain\Framework;

use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\SnapshotStatus;
use App\Domain\Sources\SourcePath;
use App\Domain\Sources\SourceRejection;
use App\Events\ApplicationRootSelected;
use App\Models\FrameworkDetection;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class FrameworkDetections
{
    public function __construct(private readonly LaravelDetector $detector) {}

    /** Idempotent per (snapshot, detector version): the snapshot never changes, so neither does the answer. */
    public function detect(SourceSnapshot $snapshot): FrameworkDetection
    {
        if ($snapshot->status !== SnapshotStatus::Ready || $snapshot->sha256 === null) {
            throw new \InvalidArgumentException('Only ready snapshots can be inspected.');
        }

        $previous = $this->current($snapshot);

        if ($previous !== null && $previous->detector_version === $this->detector->version()) {
            return $previous;
        }

        $result = $this->detector->detect($snapshot)->toArray();
        [$root, $source] = $this->initialRoot($snapshot, $result);

        // A newer detector keeps the person's earlier choice if that folder is still valid.
        if ($previous?->root_selection_source === 'user' && $previous->selected_root !== null
            && (in_array($previous->selected_root, array_column($result['roots'], 'path'), true) || $this->folderExists($snapshot, $previous->selected_root))) {
            [$root, $source] = [$previous->selected_root, 'user'];
        }

        $detection = new FrameworkDetection;
        $detection->forceFill([
            'workspace_id' => $snapshot->workspace_id,
            'project_id' => $snapshot->project_id,
            'source_snapshot_id' => $snapshot->id,
            'detector_version' => $this->detector->version(),
            'snapshot_sha256' => $snapshot->sha256,
            'status' => $result['status'],
            'result' => $result,
            'selected_root' => $root,
            'root_selection_source' => $source,
            'root_selected_by' => $source === 'user' ? $previous?->root_selected_by : null,
            'root_selected_at' => $root === null ? null : now(),
        ]);

        try {
            $detection->save();
        } catch (UniqueConstraintViolationException) {
            return $this->current($snapshot) ?? throw new \RuntimeException('Detection vanished after a race.');
        }

        AuditLog::record('framework.detected', $snapshot->project->workspace, null, $detection, [
            'status' => $result['status'],
            'roots' => count($result['roots']),
        ]);

        $root !== null && $this->announceRoot($detection, null);

        return $detection;
    }

    /** The newest detection of any detector version; pages flag it as outdated when the detector moved on. */
    public function current(SourceSnapshot $snapshot): ?FrameworkDetection
    {
        return FrameworkDetection::query()
            ->where('source_snapshot_id', $snapshot->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * The user picks the application root. It must be a folder that exists in this snapshot;
     * anything else (absolute, "..", invented) is rejected before anything is stored.
     */
    public function selectRoot(User $actor, FrameworkDetection $detection, string $root): void
    {
        if ($detection->project->isArchived()) {
            throw ProjectError::archived();
        }

        $normalized = $this->normalizeRoot($root);

        if (! in_array($normalized, $detection->rootPaths(), true) && ! $this->folderExists($detection->snapshot, $normalized)) {
            throw ValidationException::withMessages(['root' => __('That folder is not in this snapshot.')]);
        }

        $detection->forceFill([
            'selected_root' => $normalized,
            'root_selection_source' => 'user',
            'root_selected_by' => $actor->id,
            'root_selected_at' => now(),
        ])->save();

        AuditLog::record('framework.root_selected', $detection->project->workspace, $actor, $detection, ['root' => $normalized]);

        $this->announceRoot($detection, $actor->id);
    }

    /** Later stages (route discovery) react; they must never break detection itself. */
    private function announceRoot(FrameworkDetection $detection, ?int $actorId): void
    {
        try {
            ApplicationRootSelected::dispatch($detection->id, $actorId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function normalizeRoot(string $root): string
    {
        $root = trim($root);

        if ($root === '' || $root === '.' || $root === './') {
            return '.';
        }

        try {
            return SourcePath::normalize(rtrim($root, '/'), (int) config('sources.max_path_length'));
        } catch (SourceRejection) {
            throw ValidationException::withMessages(['root' => __('Use a folder path inside the snapshot, such as "backend". "..", absolute paths and backslashes are not allowed.')]);
        }
    }

    private function folderExists(SourceSnapshot $snapshot, string $folder): bool
    {
        if ($folder === '.') {
            return true;
        }

        // LIKE only narrows the candidates (it ignores case on MySQL); the exact check is done in PHP.
        foreach (SourceFile::query()
            ->where('source_snapshot_id', $snapshot->id)
            ->whereNull('excluded_reason')
            ->where('path', 'like', $folder.'/%')
            ->select('id', 'path')
            ->lazyById(500) as $file) {
            if (str_starts_with($file->path, $folder.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One clear app: select it. Several: only preselect the one the project's setup named
     * (an explicit earlier choice); never pick arbitrarily.
     *
     * @param  array<string, mixed>  $result
     * @return array{string|null, string|null}
     */
    private function initialRoot(SourceSnapshot $snapshot, array $result): array
    {
        if ($result['status'] === 'detected' && is_string($result['recommended_root'])) {
            return [$result['recommended_root'], 'auto'];
        }

        $hint = trim(preg_replace('#^(\./)+#', '', (string) $snapshot->project->root_path) ?? '', '/');
        $roots = array_map(fn ($r) => $r['path'], $result['roots']);

        if ($hint !== '' && $hint !== '.' && in_array($hint, $roots, true)) {
            return [$hint, 'project_setting'];
        }

        return [null, null];
    }
}
