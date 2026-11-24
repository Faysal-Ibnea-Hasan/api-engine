<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'event_type' => 'workspace.created',
            'metadata' => [],
        ];
    }
}
