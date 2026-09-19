<?php

namespace App\Services;

use RuntimeException;

class NumberGuessGameService
{
    public const STARTING_SECONDS = 60;
    public const CORRECT_BONUS_SECONDS = 15;
    public const STARTING_RANGE_MAX = 100;
    public const RANGE_INCREASE = 50;
    public const LEADERBOARD_LIMIT = 10;

    public function __construct(private SupabaseService $supabase) {}

    public function dashboard(array $student): array
    {
        $studentGrade = max(1, min(6, (int) ($student['grade_level'] ?? 1)));
        $result = $this->supabase->adminRpcResult('number_guess_dashboard', [
            'p_student_id' => $this->studentId($student),
            'p_limit' => self::LEADERBOARD_LIMIT,
        ]);

        if ($result['error'] !== null) {
            return $this->unconfiguredDashboard($studentGrade);
        }

        $payload = $result['data'][0] ?? null;
        if (!is_array($payload)) {
            return $this->unconfiguredDashboard($studentGrade);
        }

        return [
            'configured' => true,
            'grade' => max(1, min(6, (int) ($payload['grade_level'] ?? $studentGrade))),
            'session' => $this->normalizeSession($payload['session'] ?? null),
            'personal' => $this->normalizePersonal($payload['personal'] ?? null),
            'leaderboard' => $this->normalizeLeaderboard($payload['leaderboard'] ?? []),
            'rules' => $this->rules(),
        ];
    }

    public function start(array $student): array
    {
        return $this->action('start_number_guess_game', [
            'p_student_id' => $this->studentId($student),
        ]);
    }

    public function guess(array $student, string $sessionId, int $guess, int $expectedGuesses): array
    {
        return $this->action('submit_number_guess', [
            'p_session_id' => $sessionId,
            'p_student_id' => $this->studentId($student),
            'p_expected_guesses' => $expectedGuesses,
            'p_guess' => $guess,
        ]);
    }

    public function finish(array $student, string $sessionId): array
    {
        return $this->action('finish_number_guess_game', [
            'p_session_id' => $sessionId,
            'p_student_id' => $this->studentId($student),
        ]);
    }

    public function leaderboard(array $student): array
    {
        $dashboard = $this->dashboard($student);
        if (!$dashboard['configured']) {
            throw new RuntimeException($dashboard['message']);
        }

        return [
            'grade' => $dashboard['grade'],
            'personal' => $dashboard['personal'],
            'leaderboard' => $dashboard['leaderboard'],
        ];
    }

    private function action(string $function, array $arguments): array
    {
        $result = $this->supabase->adminRpcResult($function, $arguments);
        if ($result['error'] !== null) {
            throw new RuntimeException($this->friendlyError((string) $result['error']));
        }

        $payload = $result['data'][0] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('MathVerse could not update this game. Please try again.');
        }

        $session = $this->normalizeSession($payload['session'] ?? null);
        if ($session === null) {
            throw new RuntimeException('MathVerse received an invalid game state. Start a new game.');
        }

        $outcome = is_array($payload['outcome'] ?? null) ? $payload['outcome'] : [];
        $finished = (bool) ($outcome['finished'] ?? false);
        $safeOutcome = [
            'direction' => in_array(($outcome['direction'] ?? ''), [
                'correct', 'low', 'high', 'stale', 'finished', 'expired', 'restarted',
            ], true) ? $outcome['direction'] : null,
            'correct' => (bool) ($outcome['correct'] ?? false),
            'finished' => $finished,
        ];
        if ($finished && is_numeric($outcome['correct_number'] ?? null)) {
            $safeOutcome['correct_number'] = max(1, (int) $outcome['correct_number']);
        }

        return [
            'session' => $session,
            'personal' => $this->normalizePersonal($payload['personal'] ?? null),
            'outcome' => $safeOutcome,
        ];
    }

    private function normalizeSession(mixed $value): ?array
    {
        if (!is_array($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) ($value['id'] ?? '')) !== 1
        ) {
            return null;
        }

        $status = (string) ($value['status'] ?? '');

        return [
            'id' => (string) $value['id'],
            'status' => in_array($status, ['active', 'finished', 'expired', 'restarted'], true)
                ? $status
                : 'finished',
            'score' => max(0, (int) ($value['score'] ?? 0)),
            'guesses' => max(0, (int) ($value['guesses'] ?? 0)),
            'range_min' => 1,
            'range_max' => max(self::STARTING_RANGE_MAX, (int) ($value['range_max'] ?? self::STARTING_RANGE_MAX)),
            'started_at' => isset($value['started_at']) ? (string) $value['started_at'] : null,
            'ends_at' => isset($value['ends_at']) ? (string) $value['ends_at'] : null,
            'remaining_ms' => max(0, (int) ($value['remaining_ms'] ?? 0)),
        ];
    }

    private function normalizePersonal(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $rank = isset($value['rank']) && is_numeric($value['rank'])
            ? max(1, (int) $value['rank'])
            : null;

        return [
            'best_score' => max(0, (int) ($value['best_score'] ?? 0)),
            'best_guesses' => isset($value['best_guesses']) && is_numeric($value['best_guesses'])
                ? max(1, (int) $value['best_guesses'])
                : null,
            'games_played' => max(0, (int) ($value['games_played'] ?? 0)),
            'rank' => $rank,
            'new_best' => (bool) ($value['new_best'] ?? false),
        ];
    }

    private function normalizeLeaderboard(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row) || !is_numeric($row['rank'] ?? null)) {
                continue;
            }

            $name = trim((string) ($row['display_name'] ?? 'Student'));
            $rows[] = [
                'rank' => max(1, (int) $row['rank']),
                'display_name' => mb_substr($name !== '' ? $name : 'Student', 0, 64),
                'grade_level' => max(1, min(6, (int) ($row['grade_level'] ?? 1))),
                'best_score' => max(0, (int) ($row['best_score'] ?? 0)),
                'best_guesses' => isset($row['best_guesses']) && is_numeric($row['best_guesses'])
                    ? max(1, (int) $row['best_guesses'])
                    : null,
                'games_played' => max(0, (int) ($row['games_played'] ?? 0)),
                'is_current' => (bool) ($row['is_current'] ?? false),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);

        return array_slice($rows, 0, self::LEADERBOARD_LIMIT + 1);
    }

    private function studentId(array $student): string
    {
        $id = (string) ($student['id'] ?? '');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new RuntimeException('Your student session is invalid. Sign in again.');
        }

        return $id;
    }

    private function friendlyError(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'game session not found')) {
            return 'This game is no longer available. Start a new game.';
        }
        if (str_contains($lower, 'student profile unavailable')) {
            return 'Your student profile cannot start this game.';
        }
        if (preg_match('/enter a number from 1 to [0-9]+/i', $error, $match) === 1) {
            return ucfirst(strtolower($match[0])) . '.';
        }
        if (str_contains($lower, 'function') || str_contains($lower, 'schema cache')) {
            return 'The Number Guess database update has not been installed yet.';
        }

        return 'MathVerse could not update this game. Please try again.';
    }

    private function rules(): array
    {
        return [
            'starting_seconds' => self::STARTING_SECONDS,
            'correct_bonus_seconds' => self::CORRECT_BONUS_SECONDS,
            'starting_range_max' => self::STARTING_RANGE_MAX,
            'range_increase' => self::RANGE_INCREASE,
        ];
    }

    private function unconfiguredDashboard(int $grade): array
    {
        return [
            'configured' => false,
            'grade' => $grade,
            'message' => 'The Number Guess database update has not been installed yet.',
            'session' => null,
            'personal' => $this->normalizePersonal(null),
            'leaderboard' => [],
            'rules' => $this->rules(),
        ];
    }
}
