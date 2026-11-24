<?php

namespace App\Domain\Sources;

/** pending → validating → ready | rejected | failed (SHARED_CONTRACTS §7). Ready is final. */
enum SnapshotStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Ready = 'ready';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Ready, self::Rejected, self::Failed], true);
    }
}
