<?php

namespace App\Domain\Sources;

/** queued → running → completed | partial | failed | cancelled (SHARED_CONTRACTS §7). */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
