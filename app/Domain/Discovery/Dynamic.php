<?php

namespace App\Domain\Discovery;

/** Marks a value that can't be known without running the code. Never guessed. */
final class Dynamic
{
    public function __construct(public readonly string $why) {}
}
