<?php

namespace App\Support;

final class QuizReportStatus
{
    public static function withoutResult(
        ?string $eligibilityStatus,
        ?string $sessionStatus,
        bool $canStillSubmit = true,
    ): string
    {
        if ($eligibilityStatus === 'excused') {
            return 'Excused';
        }

        return $sessionStatus === 'completed' || !$canStillSubmit ? 'Missed' : 'Pending';
    }
}
