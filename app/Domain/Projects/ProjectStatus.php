<?php

namespace App\Domain\Projects;

/** draft → active (after a valid source import, P04+) → archived; archived restores to its previous status. */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
