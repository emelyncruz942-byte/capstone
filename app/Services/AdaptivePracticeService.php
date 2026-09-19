<?php

namespace App\Services;

use App\Support\PracticeCurriculum;
use App\Support\PracticePathSelector;
use App\Support\PracticeProblemGenerator;
use Carbon\CarbonImmutable;
use RuntimeException;

class AdaptivePracticeService
{
    public const DAILY_GOAL = 10;
    public const XP_PER_LEVEL = 250;

    public function __construct(
        private SupabaseService $supabase,
        private PracticeProblemGenerator $generator,
        private PracticePathSelector $selector
    ) {}

    public function dashboard(array $student): array
    {
        $grade = $this->grade($student);
        $catalog = $this->generator->catalogForGrade($grade);

        $profileResult = $this->supabase->adminSelectResult(
            'profiles',
            'id,grade_level,xp,points,level,trophies',
            ['id' => $student['id'], 'limit' => 1]
        );
        $profile = $profileResult['data'][0] ?? $student;

        $masteryResult = $this->supabase->adminSelectResult(
            'practice_mastery',
            'competency_key,mastery_score,difficulty,attempts,correct_answers,hints_used,last_practiced_at,next_review_at',
            [
                'student_id' => $student['id'],
                'grade_level' => $grade,
                'order' => 'mastery_score.asc,attempts.asc',
            ]
        );
        $configured = $masteryResult['error'] === null;
        $masteryRows = $configured ? $masteryResult['data'] : [];
        $masteryMap = array_column($masteryRows, null, 'competency_key');

        $history = [];
        $activeSession = null;
        if ($configured) {
            $activeSessionResult = $this->supabase->adminSelectResult(
                'practice_sessions',
                'id,mode,focus_competency_key,questions_answered,correct_answers,xp_earned,current_combo,last_activity_at',
                [
                    'student_id' => $student['id'],
                    'grade_level' => $grade,
                    'status' => 'active',
                    'order' => 'last_activity_at.desc',
                    'limit' => 1,
                ]
            );
            $configured = $activeSessionResult['error'] === null;
            $activeSession = $configured ? ($activeSessionResult['data'][0] ?? null) : null;
        }

        if ($configured) {
            $history = $this->supabase->adminSelect(
                'practice_questions',
                'answered_at,is_correct,xp_awarded,competency_key',
                [
                    'student_id' => $student['id'],
                    'answered_at' => [
                        'operator' => 'gte',
                        'value' => CarbonImmutable::now()->subDays(35)->utc()->toIso8601String(),
                    ],
                    'order' => 'answered_at.desc',
                    'limit' => 1000,
                ]
            );
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $practiceDates = [];
        $dailyAnswered = 0;
        foreach ($history as $attempt) {
            if (empty($attempt['answered_at'])) {
                continue;
            }
            $date = CarbonImmutable::parse($attempt['answered_at'], 'UTC')->setTimezone($timezone)->toDateString();
            $practiceDates[$date] = true;
            if ($date === $today) {
                $dailyAnswered++;
            }
        }

        $skills = array_map(function (array $item) use ($masteryMap): array {
            $row = $masteryMap[$item['key']] ?? [];
            $mastery = (int) ($row['mastery_score'] ?? 0);
            $attempts = (int) ($row['attempts'] ?? 0);
            $correct = (int) ($row['correct_answers'] ?? 0);

            return $item + [
                'mastery' => $mastery,
                'difficulty' => (int) ($row['difficulty'] ?? 1),
                'attempts' => $attempts,
                'accuracy' => $attempts > 0 ? (int) round(($correct / $attempts) * 100) : null,
                'status' => $this->masteryStatus($mastery, $attempts),
                'next_review_at' => $row['next_review_at'] ?? null,
            ];
        }, $catalog);

        $recommended = $this->selector->select($catalog, $masteryRows, null, count($history));
        $recommendedRow = $masteryMap[$recommended['key']] ?? [];
        $recommended += [
            'mastery' => (int) ($recommendedRow['mastery_score'] ?? 0),
            'difficulty' => (int) ($recommendedRow['difficulty'] ?? 1),
            'status' => $this->masteryStatus(
                (int) ($recommendedRow['mastery_score'] ?? 0),
                (int) ($recommendedRow['attempts'] ?? 0)
            ),
        ];

        $xp = max(0, (int) ($profile['xp'] ?? 0));
        $averageMastery = count($skills) > 0
            ? (int) round(array_sum(array_column($skills, 'mastery')) / count($skills))
            : 0;
        $terms = [];
        foreach (PracticeCurriculum::TERMS as $term) {
            $termSkills = array_values(array_filter(
                $skills,
                fn (array $skill): bool => $skill['term'] === $term
            ));
            if ($termSkills !== []) {
                $terms[] = ['label' => $term, 'skills' => $termSkills];
            }
        }

        return [
            'configured' => $configured,
            'grade' => $grade,
            'skills' => $skills,
            'terms' => $terms,
            'recommended' => $recommended,
            'average_mastery' => $averageMastery,
            'mastered_count' => count(array_filter($skills, fn (array $skill): bool => $skill['mastery'] >= 90)),
            'daily_answered' => $dailyAnswered,
            'daily_goal' => self::DAILY_GOAL,
            'daily_percent' => min(100, (int) round(($dailyAnswered / self::DAILY_GOAL) * 100)),
            'streak' => $this->practiceStreak($practiceDates, $timezone),
            'xp' => $xp,
            'level' => max(1, (int) ($profile['level'] ?? 1)),
            'trophies' => max(0, (int) ($profile['trophies'] ?? 0)),
            'xp_in_level' => $xp % self::XP_PER_LEVEL,
            'xp_per_level' => self::XP_PER_LEVEL,
            'active_session' => $activeSession,
        ];
    }

    public function startOrResume(array $student, string $mode, ?string $focusCompetency = null): array
    {
        $mode = $this->mode($mode);
        $grade = $this->grade($student);
        if ($mode === 'focus') {
            $focusCompetency = trim((string) $focusCompetency);
            if ($focusCompetency === '' || !$this->generator->isVisibleCompetency($grade, $focusCompetency)) {
                throw new RuntimeException('This curriculum topic is unavailable for your grade.');
            }
        } else {
            $focusCompetency = null;
        }
        $sessionFilters = [
            'student_id' => $student['id'],
            'grade_level' => $grade,
            'mode' => $mode,
            'status' => 'active',
            'order' => 'last_activity_at.desc',
            'limit' => 1,
        ];
        if ($mode === 'focus') {
            $sessionFilters['focus_competency_key'] = $focusCompetency;
        }
        $sessionResult = $this->supabase->adminSelectResult(
            'practice_sessions',
            '*',
            $sessionFilters
        );

        if ($sessionResult['error'] !== null) {
            throw new RuntimeException('The Learning Hub database update has not been installed yet.');
        }

        $session = $sessionResult['data'][0] ?? null;
        if (!$session) {
            $sessionData = [
                'student_id' => $student['id'],
                'grade_level' => $grade,
                'mode' => $mode,
            ];
            if ($mode === 'focus') {
                $sessionData['focus_competency_key'] = $focusCompetency;
            }
            $created = $this->supabase->adminInsertResult('practice_sessions', $sessionData);
            $session = $created['data'][0] ?? null;

            if (!$session) {
                // A simultaneous request may have won the unique active-session
                // race, so recover the row before reporting a failure.
                unset($sessionFilters['order']);
                $session = $this->supabase->adminSelect('practice_sessions', '*', $sessionFilters)[0] ?? null;
            }
        }

        if (!$session) {
            throw new RuntimeException('MathVerse could not start this practice adventure.');
        }

        // A learner may keep several topic-focus paths active. Touch the path
        // they deliberately opened so the dashboard's Continue action returns
        // to that topic, even when its unanswered question was created earlier.
        $touchedSession = $this->supabase->adminUpdate(
            'practice_sessions',
            ['last_activity_at' => CarbonImmutable::now()->utc()->toIso8601String()],
            ['id' => $session['id'], 'student_id' => $student['id']]
        );
        $session = $touchedSession[0] ?? $session;

        $question = $this->nextQuestion($student, $session['id']);
        $profile = $this->supabase->adminSelect(
            'profiles',
            'xp,level,trophies',
            ['id' => $student['id'], 'limit' => 1]
        )[0] ?? [];

        return [
            'session' => $this->publicSession($session),
            'question' => $question,
            'profile' => [
                'xp' => (int) ($profile['xp'] ?? 0),
                'level' => max(1, (int) ($profile['level'] ?? 1)),
                'trophies' => (int) ($profile['trophies'] ?? 0),
            ],
            'mode_label' => $this->modeLabel($mode),
            'daily_goal' => self::DAILY_GOAL,
        ];
    }

    public function nextQuestion(array $student, string $sessionId): array
    {
        $grade = $this->grade($student);
        $sessionResult = $this->supabase->adminSelectResult(
            'practice_sessions',
            '*',
            [
                'id' => $sessionId,
                'student_id' => $student['id'],
                'grade_level' => $grade,
                'status' => 'active',
                'limit' => 1,
            ]
        );
        $session = $sessionResult['data'][0] ?? null;
        if ($sessionResult['error'] !== null || !$session) {
            throw new RuntimeException('This practice session is unavailable.');
        }

        $openQuestion = $this->supabase->adminSelect(
            'practice_questions',
            '*',
            [
                'session_id' => $sessionId,
                'student_id' => $student['id'],
                'answered_at' => ['operator' => 'is', 'value' => 'null'],
                'limit' => 1,
            ]
        )[0] ?? null;
        if ($openQuestion) {
            return $this->publicQuestion($openQuestion, $session, $grade);
        }

        $recentQuestions = $this->supabase->adminSelect(
            'practice_questions',
            'competency_key,is_correct',
            [
                'session_id' => $sessionId,
                'student_id' => $student['id'],
                'order' => 'sequence.desc',
                'limit' => 1,
            ]
        );
        $lastQuestion = $recentQuestions[0] ?? null;
        $masteryRows = $this->supabase->adminSelect(
            'practice_mastery',
            'competency_key,mastery_score,difficulty,attempts,next_review_at',
            ['student_id' => $student['id'], 'grade_level' => $grade]
        );
        $catalog = $this->generator->catalogForGrade($grade);
        // A missed answer immediately receives a new variation of the same
        // skill. Correct answers resume the broader autonomous path.
        if (($session['mode'] ?? null) === 'focus') {
            $focusCompetency = (string) ($session['focus_competency_key'] ?? '');
            if (!$this->generator->isVisibleCompetency($grade, $focusCompetency)) {
                throw new RuntimeException('This focused curriculum topic is no longer available.');
            }
            $competency = $this->generator->competency($grade, $focusCompetency);
        } else {
            $competency = isset($lastQuestion['is_correct']) && $lastQuestion['is_correct'] === false
                ? $this->generator->competency($grade, (string) $lastQuestion['competency_key'])
                : $this->selector->select(
                    $catalog,
                    $masteryRows,
                    $lastQuestion['competency_key'] ?? null,
                    (int) ($session['questions_answered'] ?? 0),
                    (string) ($session['mode'] ?? 'adventure')
                );
        }
        $masteryMap = array_column($masteryRows, null, 'competency_key');
        $difficulty = (int) ($masteryMap[$competency['key']]['difficulty'] ?? 1);
        $sequence = (int) ($session['questions_answered'] ?? 0) + 1;
        $recentForCompetency = $this->supabase->adminSelect(
            'practice_questions',
            'prompt',
            [
                'student_id' => $student['id'],
                'competency_key' => $competency['key'],
                'order' => 'answered_at.desc.nullslast,created_at.desc',
                'limit' => 20,
            ]
        );
        $problem = $this->freshProblem(
            $grade,
            $competency['key'],
            $difficulty,
            $sessionId,
            $sequence,
            $recentForCompetency
        );

        $created = $this->supabase->adminInsertResult('practice_questions', [
            'session_id' => $sessionId,
            'student_id' => $student['id'],
            'competency_key' => $competency['key'],
            'difficulty' => $difficulty,
            'sequence' => $sequence,
            'prompt' => $problem['prompt'],
            'answer_type' => $problem['answer_type'],
            'options' => $problem['options'],
            'correct_answer' => $problem['correct_answer'],
            'hint_steps' => $problem['hints'],
            'explanation' => $problem['explanation'],
        ]);
        $question = $created['data'][0] ?? null;

        if (!$question) {
            $question = $this->supabase->adminSelect(
                'practice_questions',
                '*',
                [
                    'session_id' => $sessionId,
                    'student_id' => $student['id'],
                    'answered_at' => ['operator' => 'is', 'value' => 'null'],
                    'limit' => 1,
                ]
            )[0] ?? null;
        }

        if (!$question) {
            throw new RuntimeException('MathVerse could not prepare the next problem.');
        }

        return $this->publicQuestion($question, $session, $grade);
    }

    private function freshProblem(
        int $grade,
        string $competencyKey,
        int $difficulty,
        string $sessionId,
        int $sequence,
        array $recentQuestions
    ): array {
        $candidate = [];

        for ($retry = 0; $retry < 160; $retry++) {
            $seed = (int) hexdec(substr(
                hash('sha256', "{$sessionId}:{$sequence}:{$competencyKey}:{$retry}"),
                0,
                8
            ));
            $candidate = $this->generator->generate($grade, $competencyKey, $difficulty, $seed);

            if ($this->isFreshProblem($candidate, $recentQuestions)) {
                return $candidate;
            }
        }

        // Extremely small concept pools can eventually exhaust the recent
        // window. Always return a valid generated problem rather than block a
        // learner's endless session.
        return $candidate;
    }

    private function isFreshProblem(array $candidate, array $recentQuestions): bool
    {
        if ($recentQuestions === []) {
            return true;
        }

        $candidatePrompt = (string) ($candidate['prompt'] ?? '');
        $candidateCore = $this->corePrompt($candidatePrompt);
        $candidateNumbers = $this->numberSignature($candidateCore);
        $candidateCoreForm = $this->promptForm($candidateCore);

        foreach ($recentQuestions as $index => $recent) {
            $recentPrompt = (string) ($recent['prompt'] ?? '');
            $recentCore = $this->corePrompt($recentPrompt);
            $recentCoreForm = $this->promptForm($recentCore);

            // Do not disguise an identical example with a different opener.
            if ($candidateCore === $recentCore) {
                return false;
            }

            // Do not immediately repeat the same sentence structure. This
            // rotates equations, stories, models, and concept checks.
            if ($index === 0 && $candidateCoreForm === $recentCoreForm) {
                return false;
            }

            // When a problem structure returns later, make sure its generated
            // values have changed. Different structures may legitimately use
            // the same fixed concept number (such as a shape's four sides).
            $recentNumbers = $this->numberSignature($recentCore);
            if ($candidateNumbers !== []
                && $candidateNumbers === $recentNumbers
                && $candidateCoreForm === $recentCoreForm
            ) {
                return false;
            }
        }

        return true;
    }

    private function corePrompt(string $prompt): string
    {
        return preg_replace(
            '/^(?:Find the missing value|Try this new example|Solve carefully, then verify your result|Challenge variation|Use the definition to decide|Compare every choice carefully|Choose the best mathematical answer|Concept challenge):\s*/u',
            '',
            trim($prompt)
        ) ?? trim($prompt);
    }

    private function promptForm(string $prompt): string
    {
        $form = preg_replace('/-?\d+(?:\.\d+)?(?:st|nd|rd|th)?/u', '{n}', $prompt) ?? $prompt;
        $form = preg_replace('/★+/u', '{icons}', $form) ?? $form;
        $form = preg_replace(
            '/\b(?:Ari|Bea|Cleo|Dino|Ella|Finn|Gio|Hana|Iris|Javi|Kira|Luis)\b/u',
            '{name}',
            $form
        ) ?? $form;

        return preg_replace(
            '/\b(?:red|blue|green|gold|purple|mango|banana|guava|robotics|art|music|cats?|dogs?|fish)\b/iu',
            '{label}',
            $form
        ) ?? $form;
    }

    private function numberSignature(string $prompt): array
    {
        preg_match_all('/-?\d+(?:\.\d+)?/u', $prompt, $matches);
        preg_match_all('/★+/u', $prompt, $iconMatches);

        $iconCounts = array_map(
            fn (string $icons): string => 'icons:' . substr_count($icons, '★'),
            $iconMatches[0] ?? []
        );

        return array_merge($matches[0] ?? [], $iconCounts);
    }

    public function revealHint(array $student, string $questionId): array
    {
        $result = $this->supabase->adminRpcResult('reveal_practice_hint', [
            'p_question_id' => $questionId,
            'p_student_id' => $student['id'],
        ]);

        if ($result['error'] !== null || !isset($result['data'][0])) {
            throw new RuntimeException('MathVerse could not reveal another hint.');
        }

        return $result['data'][0];
    }

    public function submitAnswer(
        array $student,
        string $questionId,
        string $answer,
        ?int $responseMs = null
    ): array {
        $result = $this->supabase->adminRpcResult('submit_practice_answer', [
            'p_question_id' => $questionId,
            'p_student_id' => $student['id'],
            'p_answer' => $answer,
            'p_response_ms' => $responseMs,
            'p_day_started_at' => CarbonImmutable::now((string) config('app.timezone', 'UTC'))
                ->startOfDay()
                ->utc()
                ->toIso8601String(),
        ]);

        if ($result['error'] !== null || !isset($result['data'][0])) {
            throw new RuntimeException('MathVerse could not record that answer. Please try again.');
        }

        $answerResult = $result['data'][0];
        $profile = $this->supabase->adminSelect(
            'profiles',
            'xp,level,trophies',
            ['id' => $student['id'], 'limit' => 1]
        )[0] ?? [];
        $xp = (int) ($profile['xp'] ?? 0);

        $answerResult['mastery_status'] = $this->masteryStatus(
            (int) ($answerResult['mastery'] ?? 0),
            1
        );
        $answerResult['profile'] = [
            'xp' => $xp,
            'level' => max(1, (int) ($profile['level'] ?? 1)),
            'trophies' => (int) ($profile['trophies'] ?? 0),
            'xp_in_level' => $xp % self::XP_PER_LEVEL,
            'xp_per_level' => self::XP_PER_LEVEL,
        ];

        return $answerResult;
    }

    private function publicQuestion(array $question, array $session, int $grade): array
    {
        $competency = $this->generator->competency($grade, (string) $question['competency_key']);
        $hints = is_array($question['hint_steps'] ?? null) ? $question['hint_steps'] : [];
        $revealedCount = min(count($hints), max(0, (int) ($question['hints_revealed'] ?? 0)));
        $sequence = (int) $question['sequence'];

        return [
            'id' => $question['id'],
            'session_id' => $question['session_id'],
            'prompt' => $question['prompt'],
            'answer_type' => $question['answer_type'],
            'options' => is_array($question['options'] ?? null) ? array_values($question['options']) : [],
            'competency_key' => $competency['key'],
            'competency_title' => $competency['title'],
            'world' => $competency['world'],
            'icon' => $competency['icon'],
            'color' => $competency['color'],
            'difficulty' => (int) $question['difficulty'],
            'sequence' => $sequence,
            'mission' => intdiv($sequence - 1, 5) + 1,
            'mission_position' => (($sequence - 1) % 5) + 1,
            'revealed_hints' => array_slice($hints, 0, $revealedCount),
            'hints_used' => $revealedCount,
            'has_more_hints' => $revealedCount < count($hints),
            'session' => $this->publicSession($session),
        ];
    }

    private function publicSession(array $session): array
    {
        return [
            'id' => $session['id'],
            'mode' => $session['mode'],
            'focus_competency_key' => $session['focus_competency_key'] ?? null,
            'questions_answered' => (int) ($session['questions_answered'] ?? 0),
            'correct_answers' => (int) ($session['correct_answers'] ?? 0),
            'xp_earned' => (int) ($session['xp_earned'] ?? 0),
            'current_combo' => (int) ($session['current_combo'] ?? 0),
            'max_combo' => (int) ($session['max_combo'] ?? 0),
        ];
    }

    private function practiceStreak(array $practiceDates, string $timezone): int
    {
        if ($practiceDates === []) {
            return 0;
        }

        $cursor = CarbonImmutable::now($timezone)->startOfDay();
        if (!isset($practiceDates[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;
        while (isset($practiceDates[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    private function masteryStatus(int $score, int $attempts): string
    {
        if ($attempts === 0) return 'Not started';
        if ($score >= 90) return 'Mastered';
        if ($score >= 70) return 'Nearly mastered';
        if ($score >= 40) return 'Practicing';

        return 'Learning';
    }

    private function mode(string $mode): string
    {
        return in_array($mode, ['adventure', 'daily', 'review', 'focus'], true)
            ? $mode
            : 'adventure';
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'daily' => 'Daily Quest',
            'review' => 'Weak Skill Rescue',
            'focus' => 'Topic Focus',
            default => 'Endless Adventure',
        };
    }

    private function grade(array $student): int
    {
        return max(1, min(6, (int) ($student['grade_level'] ?? 1)));
    }
}
