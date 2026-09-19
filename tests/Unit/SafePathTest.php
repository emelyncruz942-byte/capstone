<?php

namespace Tests\Unit;

use App\Support\SafePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafePathTest extends TestCase
{
    #[DataProvider('safePaths')]
    public function test_it_accepts_only_local_application_paths(string $path): void
    {
        $this->assertSame($path, SafePath::normalize($path));
    }

    public static function safePaths(): array
    {
        return [
            ['/'],
            ['/student/dashboard'],
            ['/teacher/classes/123?section=results#latest'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_it_rejects_external_or_ambiguous_destinations(mixed $path): void
    {
        $this->assertNull(SafePath::normalize($path));
    }

    public static function unsafePaths(): array
    {
        return [
            [null],
            [''],
            ['student/dashboard'],
            ['https://attacker.example/path'],
            ['//attacker.example/path'],
            ['/\\attacker.example/path'],
            ['/%5c%5cattacker.example/path'],
            ['/%2f%2fattacker.example/path'],
            ['/%252f%252fattacker.example/path'],
            ["/safe\r\nLocation: https://attacker.example"],
            ['/' . str_repeat('a', 2048)],
        ];
    }
}
