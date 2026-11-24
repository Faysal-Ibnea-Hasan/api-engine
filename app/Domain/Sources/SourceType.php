<?php

namespace App\Domain\Sources;

enum SourceType: string
{
    case Zip = 'zip';
    case Git = 'git';
    /** Controlled test fixtures; local/testing only. */
    case Fixture = 'fixture';
}
