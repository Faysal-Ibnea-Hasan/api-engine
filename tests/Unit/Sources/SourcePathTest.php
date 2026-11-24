<?php

namespace Tests\Unit\Sources;

use App\Domain\Sources\SourcePath;
use App\Domain\Sources\SourceRejection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourcePathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unsafe(): iterable
    {
        foreach (['', '/etc/passwd', '../secret', 'a/../../b', 'a/./b', 'a//b', 'C:/win', 'a\\b', "a\0b", "line\nbreak", '.', '..'] as $p) {
            yield json_encode($p) => [$p];
        }
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_paths_are_rejected(string $path)
    {
        $this->expectException(SourceRejection::class);
        SourcePath::normalize($path, 1024);
    }

    public function test_safe_paths_are_normalized()
    {
        $this->assertSame('app/Http/Kernel.php', SourcePath::normalize('app/Http/Kernel.php', 1024));
        $this->assertSame('routes', SourcePath::normalize('routes/', 1024));
        // NFD "é" (e + combining accent) becomes NFC.
        $this->assertSame("caf\u{00E9}.php", SourcePath::normalize("cafe\u{0301}.php", 1024));
    }

    public function test_overlong_paths_are_rejected()
    {
        $this->expectException(SourceRejection::class);
        SourcePath::normalize(str_repeat('a/', 600).'b.php', 1024);
    }
}
