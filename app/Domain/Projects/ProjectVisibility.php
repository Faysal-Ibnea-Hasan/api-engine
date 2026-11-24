<?php

namespace App\Domain\Projects;

enum ProjectVisibility: string
{
    /** Every workspace member can see it. */
    case Workspace = 'workspace';

    /** Only Owners, Maintainers and the project's creator can see it. */
    case Restricted = 'restricted';
}
