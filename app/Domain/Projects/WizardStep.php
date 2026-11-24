<?php

namespace App\Domain\Projects;

/** Details → Source → Framework → Analysis → Review (development-plan/P02 §3.1). */
enum WizardStep: string
{
    case Details = 'details';
    case Source = 'source';
    case Framework = 'framework';
    case Analysis = 'analysis';
    case Review = 'review';

    public function next(): self
    {
        $cases = self::cases();

        return $cases[array_search($this, $cases, true) + 1] ?? self::Review;
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
