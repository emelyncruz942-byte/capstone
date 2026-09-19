<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformSecurityMigrationTest extends TestCase
{
    public function test_privileged_audit_is_transactional_and_server_only(): void
    {
        $sql = $this->migration('2026_09_12_platform_insights_and_durable_audit.sql');
        $this->assertStringContainsString('create table if not exists public.privileged_audit_outbox', $sql);
        $this->assertStringContainsString('create_privileged_audit_intent', $sql);
        $this->assertStringContainsString('complete_privileged_audit_intent', $sql);
        $this->assertStringContainsString("'security', 'high', 'pending'", $sql);
        $this->assertStringContainsString('update public.audit_logs', $sql);
        $this->assertStringContainsString("and outcome = 'pending'", $sql);
        $this->assertStringContainsString('for update', strtolower($sql));
        $this->assertStringContainsString('revoke all on public.privileged_audit_outbox from public, anon, authenticated, service_role', $sql);
    }

    public function test_audit_search_and_analytics_are_bounded(): void
    {
        $sql = $this->migration('2026_09_12_platform_insights_and_durable_audit.sql');
        $this->assertStringContainsString("when action = 'page.viewed' then 'activity'", $sql);
        $this->assertStringContainsString('limit greatest(1, least(coalesce(p_limit, 30), 100))', $sql);
        $this->assertStringContainsString('where ranked.position <= safe_limit', $sql);
        $this->assertStringContainsString('teacher_learning_hub_analytics', $sql);
        $this->assertStringContainsString('limit 200', $sql);
    }

    public function test_arcade_is_server_authoritative_bounded_and_has_no_learning_rewards(): void
    {
        $sql = $this->migration('2026_09_12_shared_math_arcade.sql');

        $this->assertStringContainsString("game_now+interval '60 seconds'", $sql);
        $this->assertStringContainsString("p_session.expires_at-clock_timestamp()", $sql);
        $this->assertStringContainsString("p_session.challenge-'answer'-'explanation'", $sql);
        $this->assertStringContainsString('and student_id=p_student_id and game_key=p_game_key for update', $sql);
        $this->assertStringContainsString('p_sequence<>session_row.sequence', $sql);
        $this->assertStringContainsString("submitted~'^-?[0-9]+$'", $sql);
        $this->assertStringContainsString('p_expected_guesses<>session_row.total_guesses', $sql);
        $this->assertStringContainsString("'direction','stale'", $sql);
        $this->assertStringContainsString('revoke all on function public.submit_number_guess(uuid,uuid,integer) from public,anon,authenticated,service_role', $sql);
        $this->assertStringContainsString('position<=greatest(3,least(coalesce(p_limit,10),50))', $sql);
        $this->assertStringContainsString('arcade_refresh_achievements', $sql);
        $this->assertStringNotContainsString('learning_profiles', $sql);
        $this->assertStringNotContainsString('total_xp', $sql);
        $this->assertStringNotContainsString('trophy_count', $sql);
    }

    private function migration(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/database/supabase/'.$name);
    }
}
