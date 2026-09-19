<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\NumberGuessGameService;
use Tests\TestCase;

class NumberGuessGameFlowTest extends TestCase
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

    public function test_student_can_open_number_guess_without_receiving_the_target(): void
    {
        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldReceive('dashboard')
            ->once()
            ->with($this->student)
            ->andReturn($this->dashboardState());

        $response = $this->withSession(['supabase_user' => $this->student])
            ->get('/student/games/number-guess');

        $response->assertOk();
        $response->assertSeeText('Number Guess');
        $response->assertSeeText('Grade 4 Leaderboard');
        $response->assertSeeText('Nova L. (You)');
        $response->assertDontSee('target_number');
        $response->assertDontSee('correct_number');
    }

    public function test_student_can_start_a_server_owned_game(): void
    {
        $result = $this->actionResult();
        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldReceive('start')
            ->once()
            ->with($this->student)
            ->andReturn($result);

        $response = $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/number-guess/start');

        $response->assertOk()
            ->assertJsonPath('session.id', self::SESSION_ID)
            ->assertJsonPath('session.range_max', 100)
            ->assertJsonPath('session.remaining_ms', 60000)
            ->assertJsonMissingPath('session.target_number');
    }

    public function test_guess_endpoint_returns_only_a_direction_and_verified_score(): void
    {
        $result = $this->actionResult();
        $result['session']['guesses'] = 3;
        $result['outcome'] = [
            'direction' => 'low',
            'correct' => false,
            'finished' => false,
        ];

        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldReceive('guess')
            ->once()
            ->with($this->student, self::SESSION_ID, 42, 2)
            ->andReturn($result);

        $response = $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/number-guess/'.self::SESSION_ID.'/guess', [
                'guess' => 42,
                'expected_guesses' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome.direction', 'low')
            ->assertJsonPath('session.guesses', 3)
            ->assertJsonMissingPath('outcome.correct_number');
    }

    public function test_guess_must_be_a_positive_bounded_integer(): void
    {
        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldNotReceive('guess');

        $response = $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/number-guess/'.self::SESSION_ID.'/guess', [
                'guess' => 0,
                'expected_guesses' => 0,
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('guess');
    }

    public function test_guess_must_include_the_seen_game_version(): void
    {
        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldNotReceive('guess');

        $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/number-guess/'.self::SESSION_ID.'/guess', ['guess' => 42])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_guesses');
    }

    public function test_student_can_finish_only_their_server_scoped_session(): void
    {
        $result = $this->actionResult();
        $result['session']['status'] = 'expired';
        $result['session']['remaining_ms'] = 0;
        $result['outcome'] = [
            'direction' => 'expired',
            'correct' => false,
            'finished' => true,
            'correct_number' => 73,
        ];

        $game = $this->mock(NumberGuessGameService::class);
        $game->shouldReceive('finish')
            ->once()
            ->with($this->student, self::SESSION_ID)
            ->andReturn($result);

        $response = $this->withSession(['supabase_user' => $this->student])
            ->postJson('/student/games/number-guess/'.self::SESSION_ID.'/finish');

        $response->assertOk()
            ->assertJsonPath('session.status', 'expired')
            ->assertJsonPath('outcome.correct_number', 73);
    }

    private function dashboardState(): array
    {
        return [
            'configured' => true,
            'grade' => 4,
            'session' => $this->actionResult()['session'],
            'personal' => [
                'best_score' => 5,
                'best_guesses' => 18,
                'games_played' => 4,
                'rank' => 1,
                'new_best' => false,
            ],
            'leaderboard' => [[
                'rank' => 1,
                'display_name' => 'Nova L.',
                'grade_level' => 4,
                'best_score' => 5,
                'best_guesses' => 18,
                'games_played' => 4,
                'is_current' => true,
            ]],
            'rules' => [
                'starting_seconds' => 60,
                'correct_bonus_seconds' => 15,
                'starting_range_max' => 100,
                'range_increase' => 50,
            ],
        ];
    }

    private function actionResult(): array
    {
        return [
            'session' => [
                'id' => self::SESSION_ID,
                'status' => 'active',
                'score' => 0,
                'guesses' => 0,
                'range_min' => 1,
                'range_max' => 100,
                'started_at' => '2026-09-11T08:00:00+00:00',
                'ends_at' => '2026-09-11T08:01:00+00:00',
                'remaining_ms' => 60000,
            ],
            'personal' => [
                'best_score' => 5,
                'best_guesses' => 18,
                'games_played' => 5,
                'rank' => 1,
                'new_best' => false,
            ],
            'outcome' => [
                'direction' => null,
                'correct' => false,
                'finished' => false,
            ],
        ];
    }
}
