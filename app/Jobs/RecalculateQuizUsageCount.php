<?php

namespace App\Jobs;

use App\Services\SupabaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateQuizUsageCount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public string $quizId) {}

    public function handle(SupabaseService $supabase): void
    {
        $quizResult = $supabase->adminSelectResult(
            'quizzes',
            'id,teacher_id',
            ['id' => $this->quizId]
        );
        $quiz = $quizResult['data'][0] ?? null;
        if ($quizResult['error'] !== null || !$quiz) {
            return;
        }

        $sessionResult = $supabase->adminSelectResult(
            'quiz_sessions',
            'id,teacher_id,class_id',
            ['source_quiz_id' => $this->quizId]
        );
        if ($sessionResult['error'] !== null) {
            return;
        }

        $usageCount = count(array_filter(
            $sessionResult['data'],
            fn (array $assignment): bool => !empty($assignment['class_id'])
                && ($assignment['teacher_id'] ?? null) !== ($quiz['teacher_id'] ?? null)
        ));

        $supabase->adminUpdate(
            'quizzes',
            ['usage_count' => $usageCount],
            ['id' => $this->quizId]
        );
    }
}
