<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubInstallation>
 */
class GitHubInstallationFactory extends Factory
{
    protected $model = GitHubInstallation::class;

    public function definition(): array
    {
        return [
            'github_installation_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'github_account_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'account_login' => fake()->unique()->userName(),
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'permissions' => ['contents' => 'read', 'metadata' => 'read'],
            'last_verified_at' => now(),
        ];
    }
}
