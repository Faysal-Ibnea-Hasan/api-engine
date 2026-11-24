<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadSession>
 */
class UploadSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $a) => Project::query()->whereKey($a['project_id'])->firstOrFail()->workspace_id,
            'created_by' => User::factory(),
            'total_bytes' => 1024,
            'status' => UploadSession::UPLOADING,
            'expires_at' => now()->addDay(),
        ];
    }
}
