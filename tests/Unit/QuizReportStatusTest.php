<?php

namespace Tests\Unit;

use App\Support\QuizReportStatus;
use PHPUnit\Framework\TestCase;

class QuizReportStatusTest extends TestCase
{
    public function test_unsubmitted_student_is_pending_while_quiz_is_waiting_or_active(): void
    {
        $this->assertSame('Pending', QuizReportStatus::withoutResult('eligible', 'waiting'));
        $this->assertSame('Pending', QuizReportStatus::withoutResult('eligible', 'active'));
    }

    public function test_unsubmitted_student_is_missed_only_after_quiz_completes(): void
    {
        $this->assertSame('Missed', QuizReportStatus::withoutResult('eligible', 'completed'));
    }

    public function test_student_without_an_available_retake_remains_missed_during_retake_mode(): void
    {
        $this->assertSame('Missed', QuizReportStatus::withoutResult('eligible', 'active', false));
        $this->assertSame('Pending', QuizReportStatus::withoutResult('eligible', 'active', true));
    }

    public function test_excused_status_wins_at_every_quiz_stage(): void
    {
        $this->assertSame('Excused', QuizReportStatus::withoutResult('excused', 'waiting'));
        $this->assertSame('Excused', QuizReportStatus::withoutResult('excused', 'completed'));
    }
}
