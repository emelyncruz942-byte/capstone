<?php

namespace App\Services;

use App\Support\PracticeCurriculum;
use Illuminate\Support\Facades\Log;

class TeacherLearningHubAnalyticsService
{
    public function __construct(private SupabaseService $supabase) {}

    public function dashboard(array $teacher, ?string $classId = null, int $days = 30): array
    {
        $days = in_array($days, [7, 30, 90, 180], true) ? $days : 30;
        try {
            $result = $this->supabase->adminRpcResult('teacher_learning_hub_analytics', [
                'p_teacher_id' => (string) ($teacher['id'] ?? ''),
                'p_class_id' => $classId,
                'p_days' => $days,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Learning Hub insights could not reach the data service.', [
                'teacher_id' => $teacher['id'] ?? null,
                'exception' => $exception::class,
            ]);
            return $this->emptyState($days, $classId, false, 'Learning Hub insights are temporarily unavailable. Your practice records are safe; try this page again shortly.');
        }

        if ($result['error'] !== null || !is_array($result['data'][0] ?? null)) {
            Log::warning('Learning Hub insights returned an unavailable or invalid result.', [
                'teacher_id' => $teacher['id'] ?? null,
                'status' => $result['status'] ?? null,
            ]);
            return $this->emptyState($days, $classId, false, 'Learning Hub insights could not be read. Check the analytics SQL migration and data-service logs, then try again.');
        }

        $payload = $result['data'][0];
        $topics = $this->topicMap();
        foreach (['weak_topics', 'recent_activity'] as $collection) {
            $payload[$collection] = $this->rows($payload[$collection] ?? null);
            foreach ($payload[$collection] as &$row) {
                $key = (string) ($row['competency_key'] ?? '');
                $row['topic_title'] = $topics[$key]['title'] ?? $this->humanizeKey($key);
                $row['topic_icon'] = $topics[$key]['icon'] ?? 'fa-book-open';
            }
            unset($row);
        }

        $state = $this->emptyState($days, $classId, true);
        if (is_array($payload['summary'] ?? null)) {
            $state['summary'] = array_replace($state['summary'], $payload['summary']);
        }
        foreach (['available_classes', 'classes', 'students', 'weak_topics', 'daily_activity', 'recent_activity'] as $key) {
            if (is_array($payload[$key] ?? null)) $state[$key] = $this->rows($payload[$key]);
        }
        $defaults = [
            'available_classes' => ['id' => '', 'name' => 'Class', 'grade_level' => null],
            'classes' => ['id' => '', 'class_name' => 'Class', 'grade_level' => null, 'students' => 0, 'active_students' => 0],
            'students' => ['name' => 'Student', 'grade_level' => null, 'classes' => '', 'last_practiced_at' => null],
            'daily_activity' => ['activity_date' => null, 'answers' => 0],
            'recent_activity' => ['student_name' => 'Student', 'is_correct' => false, 'answered_at' => null],
        ];
        foreach ($defaults as $key => $rowDefaults) {
            $state[$key] = array_map(fn (array $row): array => array_replace($rowDefaults, $row), $state[$key]);
        }
        // The earlier SQL function grouped dates in UTC. Do not label those
        // historical buckets as Philippine days before the new migration runs.
        $state['timezone'] = (string) ($payload['timezone'] ?? 'UTC');
        $state['generated_at'] = isset($payload['generated_at']) ? (string) $payload['generated_at'] : null;

        return $state;
    }

    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function topicMap(): array
    {
        $topics = [];
        for ($grade = 1; $grade <= 6; $grade++) {
            foreach (PracticeCurriculum::forGrade($grade) as $topic) $topics[$topic['key']] = $topic;
        }
        return $topics;
    }

    private function humanizeKey(string $key): string
    {
        $label = preg_replace('/^g[1-6]-/', '', $key) ?? $key;
        return ucwords(str_replace('-', ' ', $label ?: 'Unknown topic'));
    }

    private function emptyState(int $days, ?string $classId, bool $configured, ?string $message = null): array
    {
        return [
            'configured' => $configured,
            'message' => $configured ? null : $message,
            'period_days' => $days,
            'selected_class_id' => $classId,
            'generated_at' => null,
            'timezone' => \App\Support\AppDate::timezone(),
            'summary' => [
                'students' => 0, 'active_students' => 0, 'answers' => 0,
                'accuracy' => 0, 'average_mastery' => 0, 'hints_used' => 0,
                'hint_usage_rate' => 0, 'improvement' => 0,
            ],
            'available_classes' => [], 'classes' => [], 'students' => [],
            'weak_topics' => [], 'daily_activity' => [], 'recent_activity' => [],
        ];
    }
}
