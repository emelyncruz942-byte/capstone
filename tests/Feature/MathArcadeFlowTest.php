<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\MathArcadeService;
use Tests\TestCase;

class MathArcadeFlowTest extends TestCase
{
    private const STUDENT_ID = '11111111-1111-4111-8111-111111111111';
    private const SESSION_ID = '22222222-2222-4222-8222-222222222222';

    private array $student = [
        'id' => self::STUDENT_ID,
        'role' => 'student',
        'grade_level' => 4,
        'first_name' => 'Nova',
        'last_name' => 'Learner',
        'email' => 'nova@example.com',
        'avatar_url' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(SupabaseAuth::class);
    }

    public function test_student_arcade_hub_lists_four_games_and_separate_rewards(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldReceive('hub')->once()->with($this->student)->andReturn($this->hubState());

        $response = $this->withSession(['supabase_user' => $this->student])->get('/student/games');

        $response->assertOk()
            ->assertSee('data-testid="arcade-hub"', false)
            ->assertSeeText('Mental Meteor')
            ->assertSeeText('Equation Engineer')
            ->assertDontSeeText('Fraction Photon')
            ->assertSeeText('Pattern Pulse')
            ->assertSeeText('never award Learning Hub XP or trophies');
        $this->assertSame(4, substr_count($response->getContent(), 'arcade-game-card'));
    }

    public function test_game_page_never_embeds_the_current_answer(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldReceive('game')
            ->once()
            ->with($this->student, 'mental-arithmetic')
            ->andReturn($this->gameState());

        $response = $this->withSession(['supabase_user' => $this->student])
            ->get('/student/games/mental-arithmetic');

        $response->assertOk()
            ->assertSee('data-testid="arcade-game"', false)
            ->assertSeeText('Question 1')
            ->assertSeeText('(18 + 27) − 9')
            ->assertDontSee('"correct_answer":', false)
            ->assertDontSee('"answer":"36"', false);
    }

    public function test_retired_fraction_game_routes_are_unavailable_before_service_access(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        foreach (['game', 'start', 'answer', 'finish', 'leaderboard'] as $method) {
            $arcade->shouldNotReceive($method);
        }
        $this->withSession(['supabase_user' => $this->student]);
        $this->get('/student/games/fraction-comparison')->assertNotFound();
        $this->get('/student/games/fraction-comparison/leaderboard')->assertNotFound();
        $this->postJson('/student/games/fraction-comparison/start')->assertNotFound();
        $this->postJson('/student/games/fraction-comparison/'.self::SESSION_ID.'/answer')->assertNotFound();
        $this->postJson('/student/games/fraction-comparison/'.self::SESSION_ID.'/finish')->assertNotFound();
    }

    public function test_student_can_start_and_answer_a_server_owned_run(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldReceive('start')
            ->once()
            ->with($this->student, 'mental-arithmetic')
            ->andReturn($this->actionResult());
        $arcade->shouldReceive('answer')
            ->once()
            ->with($this->student, 'mental-arithmetic', self::SESSION_ID, 1, '36')
            ->andReturn(array_replace_recursive($this->actionResult(), [
                'session' => ['score' => 1, 'answers' => 1, 'sequence' => 2],
                'outcome' => [
                    'direction' => 'correct',
                    'correct' => true,
                    'finished' => false,
                    'correct_answer' => '36',
                    'explanation' => 'Add first, then subtract.',
                ],
            ]));

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/mental-arithmetic/start')
            ->assertOk()
            ->assertJsonPath('session.id', self::SESSION_ID)
            ->assertJsonMissingPath('session.challenge.answer');

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/mental-arithmetic/'.self::SESSION_ID.'/answer', [
                'sequence' => 1,
                'answer' => '36',
            ])
            ->assertOk()
            ->assertJsonPath('session.score', 1)
            ->assertJsonPath('outcome.correct', true)
            ->assertJsonPath('outcome.correct_answer', '36')
            ->assertJsonMissingPath('session.challenge.answer');
    }

    public function test_answer_is_required_and_bounded_before_the_rpc(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldNotReceive('answer');

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/pattern-pulse/'.self::SESSION_ID.'/answer', ['answer' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answer');
    }

    public function test_answer_must_include_the_question_sequence(): void
    {
        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldNotReceive('answer');

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/pattern-pulse/'.self::SESSION_ID.'/answer', ['answer' => '42'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sequence');
    }

    public function test_student_can_end_only_the_server_scoped_game_route(): void
    {
        $result = $this->actionResult();
        $result['session']['status'] = 'finished';
        $result['session']['remaining_ms'] = 0;
        $result['outcome'] = ['direction' => 'finished', 'correct' => false, 'finished' => true];

        $arcade = $this->mock(MathArcadeService::class);
        $arcade->shouldReceive('finish')
            ->once()
            ->with($this->student, 'equation-balance', self::SESSION_ID)
            ->andReturn($result);

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/equation-balance/'.self::SESSION_ID.'/finish')
            ->assertOk()
            ->assertJsonPath('session.status', 'finished')
            ->assertJsonPath('outcome.finished', true);
    }

    private function hubState(): array
    {
        $games = [];
        foreach ([
            ['number-guess', 'Number Guess', 'cyan'],
            ['mental-arithmetic', 'Mental Meteor', 'purple'],
            ['equation-balance', 'Equation Engineer', 'green'],
            ['pattern-pulse', 'Pattern Pulse', 'pink'],
        ] as [$key, $title, $accent]) {
            $games[] = [
                'key' => $key,
                'title' => $title,
                'eyebrow' => 'Reasoning Game',
                'description' => 'A varied grade-level reasoning challenge.',
                'icon' => 'fa-gamepad',
                'accent' => $accent,
                'url' => '/student/games/'.$key,
                'available' => true,
                'best_score' => 2,
                'best_streak' => 2,
                'games_played' => 1,
            ];
        }

        return [
            'configured' => true,
            'grade' => 4,
            'games' => $games,
            'badges' => [[
                'key' => 'first-launch',
                'title' => 'First Launch',
                'description' => 'Complete your first arcade run.',
                'icon' => 'fa-rocket',
                'unlocked' => true,
                'unlocked_at' => '2026-09-12T08:00:00+00:00',
            ]],
            'rules' => ['starting_seconds' => 60, 'leaderboard_limit' => 10],
        ];
    }

    private function gameState(): array
    {
        return [
            'configured' => true,
            'grade' => 4,
            'game' => [
                'key' => 'mental-arithmetic',
                'title' => 'Mental Meteor',
                'eyebrow' => 'Mental Arithmetic',
                'description' => 'Solve varied problems.',
                'icon' => 'fa-meteor',
                'accent' => 'purple',
                'url' => '/student/games/mental-arithmetic',
            ],
            'session' => $this->actionResult()['session'],
            'personal' => $this->actionResult()['personal'],
            'leaderboard' => [[
                'rank' => 1,
                'display_name' => 'Nova L.',
                'grade_level' => 4,
                'best_score' => 5,
                'best_streak' => 4,
                'games_played' => 3,
                'is_current' => true,
            ]],
            'rules' => [
                'starting_seconds' => 60,
                'leaderboard_limit' => 10,
                'leaderboard_scope' => 'grade',
                'scoring' => 'one-point-per-correct-answer',
            ],
        ];
    }

    private function actionResult(): array
    {
        return [
            'session' => [
                'id' => self::SESSION_ID,
                'game_key' => 'mental-arithmetic',
                'status' => 'active',
                'score' => 0,
                'answers' => 0,
                'correct_answers' => 0,
                'streak' => 0,
                'best_streak' => 0,
                'sequence' => 1,
                'challenge' => [
                    'prompt' => '(18 + 27) − 9',
                    'answer_type' => 'number',
                    'options' => [],
                ],
                'started_at' => '2026-09-12T08:00:00+00:00',
                'ends_at' => '2026-09-12T08:01:00+00:00',
                'remaining_ms' => 60000,
            ],
            'personal' => [
                'best_score' => 5,
                'best_streak' => 4,
                'games_played' => 3,
                'rank' => 1,
                'new_best' => false,
            ],
            'outcome' => ['direction' => 'started', 'correct' => false, 'finished' => false],
        ];
    }
}
