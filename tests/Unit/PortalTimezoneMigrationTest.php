<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PortalTimezoneMigrationTest extends TestCase
{
    public function test_insights_bucket_and_filter_by_philippine_calendar_days(): void
    {
        $sql = $this->migration();
        $this->assertStringContainsString("'timezone', 'Asia/Manila'", $sql);
        $this->assertStringContainsString("date_trunc('day', now() at time zone 'Asia/Manila')", $sql);
        $this->assertStringContainsString("group by (answered_at at time zone 'Asia/Manila')::date", $sql);
        $this->assertStringContainsString('teacher_id = p_teacher_id and archived_at is null', $sql);
        $this->assertStringContainsString('security definer set search_path = pg_catalog, public', $sql);
        $this->assertStringContainsString('from public, anon, authenticated', $sql);
    }

    public function test_fraction_retirement_is_non_destructive_and_updates_cross_game_badges(): void
    {
        $sql = $this->migration();
        $this->assertStringContainsString("p_game_key not in ('mental-arithmetic','equation-balance','pattern-pulse')", $sql);
        $this->assertStringContainsString("and game_key in ('mental-arithmetic','equation-balance','pattern-pulse')", $sql);
        $this->assertStringContainsString('games_scored>=4', $sql);
        $this->assertStringContainsString('on conflict(student_id,achievement_key) do nothing', $sql);
        $this->assertStringNotContainsString("('fraction-photon'", $sql);
        $this->assertStringNotContainsString('delete from', strtolower($sql));
        $this->assertStringNotContainsString('drop table', strtolower($sql));
    }

    private function migration(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/database/supabase/2026_09_13_portal_timezone_and_arcade_updates.sql');
    }
}
