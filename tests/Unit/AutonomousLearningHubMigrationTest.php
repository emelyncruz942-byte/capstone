<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AutonomousLearningHubMigrationTest extends TestCase
{
    public function test_practice_answers_and_scoring_are_server_only(): void
    {
        $path = dirname(__DIR__, 2)
            . '/database/supabase/2026_09_01_autonomous_learning_hub.sql';
        $sql = file_get_contents($path);

        $this->assertIsString($sql);
        $this->assertStringContainsString('revoke all on public.practice_questions from anon, authenticated', $sql);
        $this->assertStringContainsString('create or replace function public.submit_practice_answer', $sql);
        $this->assertStringContainsString('security definer', $sql);
        $this->assertStringContainsString('for update', $sql);
        $this->assertStringContainsString('answered_at is null', $sql);
    }

    public function test_topic_focus_sessions_are_scoped_by_curriculum_key(): void
    {
        $path = dirname(__DIR__, 2)
            . '/database/supabase/2026_09_01_curriculum_topic_focus.sql';
        $sql = file_get_contents($path);

        $this->assertIsString($sql);
        $this->assertStringContainsString("'adventure', 'daily', 'review', 'focus'", $sql);
        $this->assertStringContainsString('focus_competency_key', $sql);
        $this->assertStringContainsString('practice_sessions_focus_mode_check', $sql);
        $this->assertStringContainsString('practice_sessions_one_active_path_grade_idx', $sql);
    }
}
