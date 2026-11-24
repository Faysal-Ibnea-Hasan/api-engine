<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

final class AuditLog
{
    /** Keys that must never reach an audit row, even if a caller passes them. */
    private const SECRET_KEY = '/pass|token|secret|key|hash|credential/i';

    /**
     * Callers pass an explicit allowlist of metadata, never a request body.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public static function record(string $event, ?Workspace $workspace, ?User $actor, ?Model $target = null, array $metadata = []): AuditEvent
    {
        $row = new AuditEvent;
        $row->forceFill([
            'workspace_id' => $workspace?->getKey(),
            'actor_id' => $actor?->getKey(),
            'event_type' => $event,
            'target_type' => $target === null ? null : class_basename($target),
            'target_id' => $target === null ? null : (string) $target->getKey(),
            'metadata' => array_filter(
                $metadata,
                fn (string $key) => preg_match(self::SECRET_KEY, $key) !== 1,
                ARRAY_FILTER_USE_KEY,
            ),
            'correlation_id' => Context::get('correlation_id'),
        ])->save();

        return $row;
    }
}
