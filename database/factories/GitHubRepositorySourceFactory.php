<?php

namespace Database\Factories;

use App\Domain\Sources\SourceType;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepositorySource;
use App\Models\Project;
use App\Models\ProjectSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubRepositorySource>
 */
class GitHubRepositorySourceFactory extends Factory
{
    protected $model = GitHubRepositorySource::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $a) => Project::query()->whereKey($a['project_id'])->firstOrFail()->workspace_id,
            'project_source_id' => fn (array $a) => ProjectSource::factory()->create(['project_id' => $a['project_id'], 'type' => SourceType::Git])->id,
            'github_installation_id' => GitHubInstallation::factory(),
            'github_repository_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'owner_login' => 'acme',
            'repository_name' => 'api',
            'full_name' => 'acme/api',
            'visibility' => 'private',
            'default_branch' => 'main',
        ];
    }
}
