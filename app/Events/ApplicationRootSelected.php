<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A detection now has a selected application root (automatically or by a person). Discovery listens. */
final class ApplicationRootSelected
{
    use Dispatchable;

    public function __construct(public readonly string $detectionId, public readonly ?int $actorId = null) {}
}
