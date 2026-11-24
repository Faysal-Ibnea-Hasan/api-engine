<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The immutable Git origin of one import: repository, requested ref and the commit it resolved to.
 * Never holds tokens, OAuth codes or archive URLs.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $github_repository_source_id
 * @property int $github_repository_id
 * @property string $repository_full_name
 * @property string|null $processing_run_id
 * @property string|null $source_snapshot_id
 * @property string $requested_ref
 * @property string $ref_type
 * @property string $commit_sha
 * @property string|null $commit_message
 * @property Carbon|null $committed_at
 * @property string $status
 * @property string|null $failure_code
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GitHubRepositorySource $repositorySource
 * @property-read ProcessingRun|null $run
 */
class GitHubImport extends Model
{
    use BelongsToWorkspace, HasUuids;

    /** queued → downloading → importing → completed | failed | cancelled */
    public const QUEUED = 'queued';

    public const DOWNLOADING = 'downloading';

    public const IMPORTING = 'importing';

    protected $table = 'github_imports';

    protected function casts(): array
    {
        return ['github_repository_id' => 'integer', 'committed_at' => 'datetime'];
    }

    /** @return BelongsTo<GitHubRepositorySource, $this> */
    public function repositorySource(): BelongsTo
    {
        return $this->belongsTo(GitHubRepositorySource::class, 'github_repository_source_id');
    }

    /** @return BelongsTo<ProcessingRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'processing_run_id');
    }
}
