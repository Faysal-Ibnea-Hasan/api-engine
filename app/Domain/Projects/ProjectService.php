<?php

namespace App\Domain\Projects;

use App\Domain\Audit\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every project write. Edits are optimistic: the caller sends the lock_version it
 * read, and a write against a newer version fails with ProjectError::stale().
 * Nothing here imports, scans or calls AI; the wizard only records preferences.
 */
final class ProjectService
{
    /** @param  array{name: string, slug?: string|null, description?: string|null, visibility: string}  $details */
    public function create(User $actor, Workspace $workspace, array $details): Project
    {
        $slug = $details['slug'] ?? null;

        try {
            return DB::transaction(function () use ($actor, $workspace, $details, $slug) {
                $project = new Project;
                $project->forceFill([
                    'workspace_id' => $workspace->id,
                    'created_by' => $actor->id,
                    'name' => $details['name'],
                    'slug' => $slug ?: $this->freeSlug($workspace, $details['name']),
                    'description' => $details['description'] ?? null,
                    'visibility' => ProjectVisibility::from($details['visibility']),
                    'status' => ProjectStatus::Draft,
                    'wizard_step' => WizardStep::Details->next(),
                ])->save();

                AuditLog::record('project.created', $workspace, $actor, $project, ['name' => $project->name, 'slug' => $project->slug]);

                return $project;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugTaken(); // lost a race for the same slug
        }
    }

    /** @param  array{name: string, slug: string, description?: string|null, visibility: string}  $details */
    public function updateDetails(User $actor, Project $project, array $details, int $lockVersion): void
    {
        $this->write($actor, $project, $lockVersion, [
            'name' => $details['name'],
            'slug' => $details['slug'],
            'description' => $details['description'] ?? null,
            'visibility' => $details['visibility'],
        ]);
    }

    /** @param  array<string, string>  $settings  validated fields for this step only */
    public function saveStep(User $actor, Project $project, WizardStep $step, array $settings, int $lockVersion): void
    {
        // Steps open in order until setup is first completed: no skipping ahead to Review.
        if ($project->wizard_completed_at === null && $step->position() > $project->wizard_step->position()) {
            throw ProjectError::stepNotReached();
        }

        $furthest = $step->next()->position() > $project->wizard_step->position() ? $step->next() : $project->wizard_step;
        $completing = $step === WizardStep::Review && $project->wizard_completed_at === null;

        $this->write($actor, $project, $lockVersion, $settings + [
            'wizard_step' => $furthest->value,
            'wizard_completed_at' => $completing ? now() : $project->wizard_completed_at,
        ], audit: $completing ? 'project.setup_completed' : null);
    }

    /** First ready snapshot: draft → active. An archived draft will restore as active. */
    public function activate(Project $project): void
    {
        $activated = Project::query()->whereKey($project->id)->where('status', ProjectStatus::Draft->value)
            ->update(['status' => ProjectStatus::Active->value, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);

        Project::query()->whereKey($project->id)->where('status', ProjectStatus::Archived->value)
            ->where('status_before_archive', ProjectStatus::Draft->value)
            ->update(['status_before_archive' => ProjectStatus::Active->value, 'lock_version' => DB::raw('lock_version + 1')]);

        if ($activated === 1) {
            AuditLog::record('project.activated', $project->workspace, null, $project);
        }
    }

    public function archive(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->isArchived()) {
                throw ProjectError::archived();
            }

            $project->forceFill([
                'status_before_archive' => $project->status,
                'status' => ProjectStatus::Archived,
                'archived_at' => now(),
                'lock_version' => $project->lock_version + 1,
            ])->save();

            AuditLog::record('project.archived', $project->workspace, $actor, $project);
        });
    }

    public function restore(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (! $project->isArchived()) {
                throw ProjectError::notArchived();
            }

            $project->forceFill([
                'status' => $project->status_before_archive ?? ProjectStatus::Draft,
                'status_before_archive' => null,
                'archived_at' => null,
                'lock_version' => $project->lock_version + 1,
            ])->save();

            AuditLog::record('project.restored', $project->workspace, $actor, $project, ['status' => $project->status->value]);
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(User $actor, Project $project, int $lockVersion, array $attributes, ?string $audit = 'project.updated'): void
    {
        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $changed = array_keys(array_filter(
            $attributes,
            fn ($value, string $key) => ! in_array($key, ['wizard_step', 'wizard_completed_at'], true)
                && $this->raw($project->getAttribute($key)) !== $this->raw($value),
            ARRAY_FILTER_USE_BOTH,
        ));

        try {
            $updated = Project::query()
                ->whereKey($project->id)
                ->where('lock_version', $lockVersion)
                ->where('status', '!=', ProjectStatus::Archived->value)
                ->update($attributes + ['lock_version' => $lockVersion + 1, 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw $this->slugTaken();
        }

        if ($updated === 0) {
            throw ProjectError::stale();
        }

        if ($audit !== null && ($changed !== [] || $audit !== 'project.updated')) {
            AuditLog::record($audit, $project->workspace, $actor, $project, ['fields' => implode(',', $changed)]);
        }
    }

    private function raw(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function freeSlug(Workspace $workspace, string $name): string
    {
        $base = Str::limit(Str::slug($name), 70, '') ?: 'project';
        $taken = Project::query()->forWorkspace($workspace)->where('slug', 'like', "{$base}%")->pluck('slug')->all();

        for ($n = 1, $slug = $base; in_array($slug, $taken, true); $n++) {
            $slug = "{$base}-".($n + 1);
        }

        return $slug;
    }

    private function slugTaken(): ValidationException
    {
        return ValidationException::withMessages(['slug' => __('Another project in this workspace already uses this slug.')]);
    }
}
