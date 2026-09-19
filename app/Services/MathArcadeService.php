<?php

namespace App\Services;

use RuntimeException;

class MathArcadeService
{
    public const STARTING_SECONDS = 60;
    public const LEADERBOARD_LIMIT = 10;

    private const SHARED_GAME_KEYS = [
        'mental-arithmetic',
        'equation-balance',
        'pattern-pulse',
    ];

    private const GAMES = [
        'number-guess' => [
            'title' => 'Number Guess',
            'eyebrow' => 'Logic Sprint',
            'description' => 'Use high-or-low clues to locate changing hidden numbers before time runs out.',
            'icon' => 'fa-magnifying-glass',
            'accent' => 'cyan',
            'url' => '/student/games/number-guess',
        ],
        'mental-arithmetic' => [
            'title' => 'Mental Meteor',
            'eyebrow' => 'Mental Arithmetic',
            'description' => 'Solve multi-step number challenges and missing-value problems without a calculator.',
            'icon' => 'fa-meteor',
            'accent' => 'purple',
            'url' => '/student/games/mental-arithmetic',
        ],
        'equation-balance' => [
            'title' => 'Equation Engineer',
            'eyebrow' => 'Equation Balance',
            'description' => 'Undo operations and keep both sides balanced to discover the unknown value.',
            'icon' => 'fa-scale-balanced',
            'accent' => 'green',
            'url' => '/student/games/equation-balance',
        ],
        'pattern-pulse' => [
            'title' => 'Pattern Pulse',
            'eyebrow' => 'Sequence Reasoning',
            'description' => 'Detect alternating, growing, multiplicative, and interleaved number patterns.',
            'icon' => 'fa-wave-square',
            'accent' => 'pink',
            'url' => '/student/games/pattern-pulse',
        ],
    ];

    private const BADGES = [
        'first-launch' => ['title' => 'First Launch', 'description' => 'Complete your first arcade run.', 'icon' => 'fa-rocket'],
        'score-five' => ['title' => 'Score Five', 'description' => 'Reach a score of 5 in any game.', 'icon' => 'fa-star'],
        'score-ten' => ['title' => 'Double Digits', 'description' => 'Reach a score of 10 in any game.', 'icon' => 'fa-bolt'],
        'streak-five' => ['title' => 'Five in Flight', 'description' => 'Build a five-answer correct streak.', 'icon' => 'fa-fire'],
        'arcade-explorer' => ['title' => 'Arcade Explorer', 'description' => 'Score in three different games.', 'icon' => 'fa-compass'],
        'arcade-master' => ['title' => 'Arcade Master', 'description' => 'Score in every MathVerse game.', 'icon' => 'fa-crown'],
        'arcade-veteran' => ['title' => 'Arcade Veteran', 'description' => 'Complete 25 game runs.', 'icon' => 'fa-medal'],
        'number-navigator' => ['title' => 'Number Navigator', 'description' => 'Score 5 in Number Guess.', 'icon' => 'fa-location-crosshairs'],
        'mental-meteor' => ['title' => 'Mental Meteor', 'description' => 'Score 10 in Mental Arithmetic.', 'icon' => 'fa-meteor'],
        'equation-engineer' => ['title' => 'Equation Engineer', 'description' => 'Score 10 in Equation Balance.', 'icon' => 'fa-scale-balanced'],
        'pattern-pilot' => ['title' => 'Pattern Pilot', 'description' => 'Score 10 in Pattern Pulse.', 'icon' => 'fa-wave-square'],
    ];

    public function __construct(private SupabaseService $supabase) {}

    public function hub(array $student): array
    {
        $grade = $this->studentGrade($student);
        $result = $this->supabase->adminRpcResult('arcade_hub_dashboard', [
            'p_student_id' => $this->studentId($student),
        ]);

        if ($result['error'] !== null) {
            return $this->unconfiguredHub($grade);
        }

        $payload = $result['data'][0] ?? null;
        if (!is_array($payload)) {
            return $this->unconfiguredHub($grade);
        }

        $scores = [];
        foreach (is_array($payload['scores'] ?? null) ? $payload['scores'] : [] as $row) {
            if (!is_array($row) || !isset(self::GAMES[(string) ($row['game_key'] ?? '')])) {
                continue;
            }
            $scores[(string) $row['game_key']] = $this->normalizeScore($row);
        }

        $games = [];
        foreach (self::GAMES as $key => $definition) {
            $games[] = array_merge($definition, [
                'key' => $key,
                'available' => true,
            ], $scores[$key] ?? $this->normalizeScore(null));
        }

        return [
            'configured' => true,
            'grade' => max(1, min(6, (int) ($payload['grade_level'] ?? $grade))),
            'games' => $games,
            'badges' => $this->normalizeBadges($payload['achievements'] ?? []),
            'rules' => $this->rules(),
        ];
    }

    public function game(array $student, string $gameKey): array
    {
        $definition = $this->gameDefinition($gameKey);
        $grade = $this->studentGrade($student);
        $result = $this->supabase->adminRpcResult('arcade_game_dashboard', [
            'p_student_id' => $this->studentId($student),
            'p_game_key' => $gameKey,
            'p_limit' => self::LEADERBOARD_LIMIT,
        ]);

        if ($result['error'] !== null) {
            return $this->unconfiguredGame($grade, $gameKey, $definition);
        }

        $payload = $result['data'][0] ?? null;
        if (!is_array($payload)) {
            return $this->unconfiguredGame($grade, $gameKey, $definition);
        }

        return [
            'configured' => true,
            'grade' => max(1, min(6, (int) ($payload['grade_level'] ?? $grade))),
            'game' => array_merge($definition, ['key' => $gameKey]),
            'session' => $this->normalizeSession($payload['session'] ?? null, $gameKey),
            'personal' => $this->normalizePersonal($payload['personal'] ?? null),
            'leaderboard' => $this->normalizeLeaderboard($payload['leaderboard'] ?? []),
            'rules' => $this->rules(),
        ];
    }

    public function start(array $student, string $gameKey): array
    {
        $this->gameDefinition($gameKey);

        return $this->action('start_arcade_game', $gameKey, [
            'p_student_id' => $this->studentId($student),
            'p_game_key' => $gameKey,
        ]);
    }

    public function answer(array $student, string $gameKey, string $sessionId, int $sequence, string $answer): array
    {
        $this->gameDefinition($gameKey);
        $answer = trim($answer);
        if ($answer === '' || mb_strlen($answer) > 40) {
            throw new RuntimeException('Enter a valid answer.');
        }

        return $this->action('submit_arcade_answer', $gameKey, [
            'p_session_id' => $sessionId,
            'p_student_id' => $this->studentId($student),
            'p_game_key' => $gameKey,
            'p_sequence' => $sequence,
            'p_answer' => $answer,
        ]);
    }

    public function finish(array $student, string $gameKey, string $sessionId): array
    {
        $this->gameDefinition($gameKey);

        return $this->action('finish_arcade_game', $gameKey, [
            'p_session_id' => $sessionId,
            'p_student_id' => $this->studentId($student),
            'p_game_key' => $gameKey,
        ]);
    }

    public function leaderboard(array $student, string $gameKey): array
    {
        $dashboard = $this->game($student, $gameKey);
        if (!$dashboard['configured']) {
            throw new RuntimeException($dashboard['message']);
        }

        return [
            'grade' => $dashboard['grade'],
            'personal' => $dashboard['personal'],
            'leaderboard' => $dashboard['leaderboard'],
        ];
    }

    private function action(string $function, string $gameKey, array $arguments): array
    {
        $result = $this->supabase->adminRpcResult($function, $arguments);
        if ($result['error'] !== null) {
            throw new RuntimeException($this->friendlyError((string) $result['error']));
        }

        $payload = $result['data'][0] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('MathVerse could not update this game. Please try again.');
        }

        $session = $this->normalizeSession($payload['session'] ?? null, $gameKey);
        if ($session === null) {
            throw new RuntimeException('MathVerse received an invalid game state. Start a new game.');
        }

        $outcome = is_array($payload['outcome'] ?? null) ? $payload['outcome'] : [];
        $direction = (string) ($outcome['direction'] ?? '');
        $safeOutcome = [
            'direction' => in_array($direction, ['started', 'correct', 'incorrect', 'stale', 'finished', 'expired'], true)
                ? $direction
                : null,
            'correct' => (bool) ($outcome['correct'] ?? false),
            'finished' => (bool) ($outcome['finished'] ?? false),
        ];

        if (isset($outcome['correct_answer']) && is_scalar($outcome['correct_answer'])) {
            $safeOutcome['correct_answer'] = mb_substr(trim((string) $outcome['correct_answer']), 0, 40);
        }
        if (isset($outcome['explanation']) && is_scalar($outcome['explanation'])) {
            $safeOutcome['explanation'] = mb_substr(trim((string) $outcome['explanation']), 0, 300);
        }

        return [
            'session' => $session,
            'personal' => $this->normalizePersonal($payload['personal'] ?? null),
            'outcome' => $safeOutcome,
        ];
    }

    private function normalizeSession(mixed $value, string $expectedGame): ?array
    {
        if (!is_array($value)
            || !$this->isUuid((string) ($value['id'] ?? ''))
            || (string) ($value['game_key'] ?? '') !== $expectedGame
        ) {
            return null;
        }

        $challenge = is_array($value['challenge'] ?? null) ? $value['challenge'] : [];
        $prompt = trim((string) ($challenge['prompt'] ?? ''));
        $answerType = (string) ($challenge['answer_type'] ?? '');
        if ($prompt === '' || !in_array($answerType, ['number', 'choice'], true)) {
            return null;
        }

        $options = [];
        if ($answerType === 'choice' && is_array($challenge['options'] ?? null)) {
            foreach (array_slice($challenge['options'], 0, 8) as $option) {
                if (!is_scalar($option)) {
                    continue;
                }
                $option = mb_substr(trim((string) $option), 0, 40);
                if ($option !== '' && !in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
        }
        if ($answerType === 'choice' && $options === []) {
            return null;
        }

        $status = (string) ($value['status'] ?? '');

        return [
            'id' => strtolower((string) $value['id']),
            'game_key' => $expectedGame,
            'status' => in_array($status, ['active', 'finished', 'expired', 'restarted'], true) ? $status : 'finished',
            'score' => max(0, min(1000000, (int) ($value['score'] ?? 0))),
            'answers' => max(0, min(1000000, (int) ($value['answers'] ?? 0))),
            'correct_answers' => max(0, min(1000000, (int) ($value['correct_answers'] ?? 0))),
            'streak' => max(0, min(1000000, (int) ($value['streak'] ?? 0))),
            'best_streak' => max(0, min(1000000, (int) ($value['best_streak'] ?? 0))),
            'sequence' => max(1, min(1000001, (int) ($value['sequence'] ?? 1))),
            'challenge' => [
                'prompt' => mb_substr($prompt, 0, 240),
                'answer_type' => $answerType,
                'options' => $options,
            ],
            'started_at' => isset($value['started_at']) ? mb_substr((string) $value['started_at'], 0, 50) : null,
            'ends_at' => isset($value['ends_at']) ? mb_substr((string) $value['ends_at'], 0, 50) : null,
            'remaining_ms' => max(0, min(65000, (int) ($value['remaining_ms'] ?? 0))),
        ];
    }

    private function normalizePersonal(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'best_score' => max(0, min(1000000, (int) ($value['best_score'] ?? 0))),
            'best_streak' => max(0, min(1000000, (int) ($value['best_streak'] ?? 0))),
            'games_played' => max(0, min(1000000, (int) ($value['games_played'] ?? 0))),
            'rank' => isset($value['rank']) && is_numeric($value['rank']) ? max(1, (int) $value['rank']) : null,
            'new_best' => (bool) ($value['new_best'] ?? false),
        ];
    }

    private function normalizeScore(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'best_score' => max(0, min(1000000, (int) ($value['best_score'] ?? 0))),
            'best_streak' => max(0, min(1000000, (int) ($value['best_streak'] ?? 0))),
            'games_played' => max(0, min(1000000, (int) ($value['games_played'] ?? 0))),
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
                'best_score' => max(0, min(1000000, (int) ($row['best_score'] ?? 0))),
                'best_streak' => max(0, min(1000000, (int) ($row['best_streak'] ?? 0))),
                'games_played' => max(0, min(1000000, (int) ($row['games_played'] ?? 0))),
                'is_current' => (bool) ($row['is_current'] ?? false),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);

        return array_slice($rows, 0, self::LEADERBOARD_LIMIT + 1);
    }

    private function normalizeBadges(mixed $value): array
    {
        $unlocked = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row) || !isset(self::BADGES[(string) ($row['key'] ?? '')])) {
                continue;
            }
            $unlocked[(string) $row['key']] = isset($row['unlocked_at'])
                ? mb_substr((string) $row['unlocked_at'], 0, 50)
                : null;
        }

        $badges = [];
        foreach (self::BADGES as $key => $definition) {
            $badges[] = array_merge($definition, [
                'key' => $key,
                'unlocked' => array_key_exists($key, $unlocked),
                'unlocked_at' => $unlocked[$key] ?? null,
            ]);
        }

        return $badges;
    }

    private function gameDefinition(string $gameKey): array
    {
        if (!in_array($gameKey, self::SHARED_GAME_KEYS, true)) {
            throw new RuntimeException('That MathVerse arcade game is not available.');
        }

        return self::GAMES[$gameKey];
    }

    private function studentId(array $student): string
    {
        $id = (string) ($student['id'] ?? '');
        if (!$this->isUuid($id)) {
            throw new RuntimeException('Your student session is invalid. Sign in again.');
        }

        return strtolower($id);
    }

    private function studentGrade(array $student): int
    {
        return max(1, min(6, (int) ($student['grade_level'] ?? 1)));
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function friendlyError(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'session not found') || str_contains($lower, 'session has ended')) {
            return 'This arcade run has ended. Start a new game.';
        }
        if (str_contains($lower, 'student profile unavailable')) {
            return 'Your student profile cannot start this game.';
        }
        if (str_contains($lower, 'valid answer')) {
            return 'Enter a valid answer.';
        }
        if (str_contains($lower, 'unsupported arcade game')) {
            return 'That MathVerse arcade game is not available.';
        }
        if (str_contains($lower, 'function') || str_contains($lower, 'schema cache')) {
            return 'The shared Math Arcade database update has not been installed yet.';
        }

        return 'MathVerse could not update this game. Please try again.';
    }

    private function rules(): array
    {
        return [
            'starting_seconds' => self::STARTING_SECONDS,
            'leaderboard_limit' => self::LEADERBOARD_LIMIT,
            'leaderboard_scope' => 'grade',
            'scoring' => 'one-point-per-correct-answer',
        ];
    }

    private function unconfiguredHub(int $grade): array
    {
        $games = [];
        foreach (self::GAMES as $key => $definition) {
            $games[] = array_merge($definition, [
                'key' => $key,
                'available' => $key === 'number-guess',
            ], $this->normalizeScore(null));
        }

        return [
            'configured' => false,
            'grade' => $grade,
            'message' => 'The shared Math Arcade database update has not been installed yet. Number Guess is still available.',
            'games' => $games,
            'badges' => $this->normalizeBadges([]),
            'rules' => $this->rules(),
        ];
    }

    private function unconfiguredGame(int $grade, string $gameKey, array $definition): array
    {
        return [
            'configured' => false,
            'grade' => $grade,
            'message' => 'The shared Math Arcade database update has not been installed yet.',
            'game' => array_merge($definition, ['key' => $gameKey]),
            'session' => null,
            'personal' => $this->normalizePersonal(null),
            'leaderboard' => [],
            'rules' => $this->rules(),
        ];
    }
}
