<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EnvironmentIgnoreTest extends TestCase
{
    public function test_secret_environment_file_variants_are_ignored(): void
    {
        $rules = file(
            dirname(__DIR__, 2).'/.gitignore',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );

        $this->assertIsArray($rules);
        $this->assertContains('.env', $rules);
        $this->assertContains('.env.*', $rules);
        $this->assertContains('!.env.example', $rules);
        $this->assertContains('.envrc', $rules);
        $this->assertContains('.direnv/', $rules);
    }
}
