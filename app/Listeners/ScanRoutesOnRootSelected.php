<?php

namespace App\Listeners;

use App\Domain\Discovery\RouteScans;
use App\Events\ApplicationRootSelected;
use App\Models\FrameworkDetection;
use App\Models\User;
use App\Support\Features;

/** Once a root is known, discover its routes. Starting an existing scan is a no-op, so repeats are harmless. */
class ScanRoutesOnRootSelected
{
    public function handle(ApplicationRootSelected $event): void
    {
        if (! Features::enabled('route_discovery')) {
            return;
        }

        $detection = FrameworkDetection::query()->find($event->detectionId);

        if ($detection !== null && $detection->selected_root !== null && ! $detection->project->isArchived()) {
            app(RouteScans::class)->start($event->actorId === null ? null : User::query()->find($event->actorId), $detection);
        }
    }
}
