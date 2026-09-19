<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NumberGuessGameSecurityTest extends TestCase
{
    public function test_game_tables_and_functions_are_server_only(): void
    {
        $migration = (string) file_get_contents($this->projectPath(
            'database/supabase/2026_09_11_number_guess_game.sql'
        ));

        $this->assertStringContainsString('alter table public.number_guess_sessions enable row level security', $migration);
        $this->assertStringContainsString('alter table public.number_guess_scores enable row level security', $migration);
        $this->assertStringContainsString(
            'revoke all on public.number_guess_sessions from public, anon, authenticated',
            $migration
        );
        $this->assertStringContainsString(
            'revoke all on public.number_guess_scores from public, anon, authenticated',
            $migration
        );
        $this->assertStringContainsString('security definer', strtolower($migration));
        $this->assertStringContainsString('set search_path = pg_catalog, public', $migration);
        $this->assertStringNotContainsString('to authenticated', strtolower($migration));
        $this->assertStringContainsString('profiles.grade_level = student_grade', $migration);
        $this->assertStringContainsString("then 'Anonymous Student'", $migration);
    }

    public function test_secret_number_is_not_referenced_by_browser_or_blade_code(): void
    {
        foreach ([
            'public/js/number-guess.js',
            'resources/views/student/games/number-guess.blade.php',
            'app/Services/NumberGuessGameService.php',
        ] as $relativePath) {
            $source = (string) file_get_contents($this->projectPath($relativePath));
            $this->assertStringNotContainsString(
                'target_number',
                $source,
                "The protected target leaked into {$relativePath}."
            );
        }
    }

    public function test_game_rules_are_enforced_in_the_transactional_database_function(): void
    {
        $migration = (string) file_get_contents($this->projectPath(
            'database/supabase/2026_09_11_number_guess_game.sql'
        ));

        $this->assertStringContainsString("game_now + interval '60 seconds'", $migration);
        $this->assertStringContainsString("session_row.expires_at + interval '15 seconds'", $migration);
        $this->assertStringContainsString('session_row.range_max + 50', $migration);
        $this->assertStringContainsString('for update', strtolower($migration));
        $this->assertStringContainsString('and student_id = p_student_id', $migration);
    }

    private function projectPath(string $relativePath): string
    {
        return dirname(__DIR__, 2).'/'.$relativePath;
    }
}
