<?php

namespace App\Support;

final class Features
{
    public static function enabled(string $flag): bool
    {
        return config("features.{$flag}") === true;
    }
}
