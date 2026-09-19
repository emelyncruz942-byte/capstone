<?php

namespace Tests\Unit;

use App\Services\MathArcadeService;
use App\Services\SupabaseService;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MathArcadeServiceTest extends TestCase
{
    private const STUDENT_ID = '11111111-1111-4111-8111-111111111111';
    private const SESSION_ID = '22222222-2222-4222-8222-222222222222';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_game_dashboard_strips_server_answer_and_explanation(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('arcade_game_dashboard', [
                'p_student_id' => self::STUDENT_ID,
                'p_game_key' => 'mental-arithmetic',
                'p_limit' => 10,
            ])
            ->andReturn($this->rpcPayload());

        $state = (new MathArcadeService($supabase))->game($this->student(), 'mental-arithmetic');

        $this->assertTrue($state['configured']);
        $this->assertSame('(18 + 27) − 9', $state['session']['challenge']['prompt']);
        $this->assertArrayNotHasKey('answer', $state['session']['challenge']);
        $this->assertArrayNotHasKey('explanation', $state['session']['challenge']);
        $this->assertArrayNotHasKey('student_id', $state['session']);
    }

    public function test_answer_result_reveals_old_answer_but_never_the_next_one(): void
    {
        $payload = $this->rpcPayload();
        $payload['data'][0]['outcome'] = [
            'direction' => 'incorrect',
            'correct' => false,
            'finished' => false,
            'correct_answer' => '36',
            'explanation' => 'Add first, then subtract.',
        ];

        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('submit_arcade_answer', [
                'p_session_id' => self::SESSION_ID,
                'p_student_id' => self::STUDENT_ID,
                'p_game_key' => 'mental-arithmetic',
                'p_sequence' => 1,
                'p_answer' => '35',
            ])
            ->andReturn($payload);

        $result = (new MathArcadeService($supabase))->answer(
            $this->student(),
            'mental-arithmetic',
            self::SESSION_ID,
            1,
            '35'
        );

        $this->assertSame('36', $result['outcome']['correct_answer']);
        $this->assertSame('Add first, then subtract.', $result['outcome']['explanation']);
        $this->assertArrayNotHasKey('answer', $result['session']['challenge']);
        $this->assertArrayNotHasKey('explanation', $result['session']['challenge']);
    }

    public function test_hub_has_four_games_and_shared_badges_without_progress_currency(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [[
                'grade_level' => 4,
                'scores' => [[
                    'game_key' => 'mental-arithmetic',
                    'best_score' => 8,
                    'best_streak' => 5,
                    'games_played' => 3,
                ]],
                'achievements' => [[
                    'key' => 'streak-five',
                    'unlocked_at' => '2026-09-12T08:00:00+00:00',
                ]],
            ]],
            'error' => null,
            'status' => 200,
        ]);

        $hub = (new MathArcadeService($supabase))->hub($this->student());

        $this->assertCount(4, $hub['games']);
        $this->assertCount(11, $hub['badges']);
        $this->assertNotContains('fraction-comparison', array_column($hub['games'], 'key'));
        $this->assertNotContains('fraction-photon', array_column($hub['badges'], 'key'));
        $streakBadge = array_values(array_filter(
            $hub['badges'],
            fn (array $badge): bool => $badge['key'] === 'streak-five'
        ))[0];
        $this->assertTrue($streakBadge['unlocked']);
        $this->assertArrayNotHasKey('xp', $hub);
        $this->assertArrayNotHasKey('trophies', $hub);
    }

    public function test_unsupported_game_is_rejected_before_database_access(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldNotReceive('adminRpcResult');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        (new MathArcadeService($supabase))->start($this->student(), 'made-up-game');
    }

    public function test_retired_fraction_game_cannot_start_even_with_an_existing_score(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldNotReceive('adminRpcResult');
        $this->expectException(RuntimeException::class);
        (new MathArcadeService($supabase))->start($this->student(), 'fraction-comparison');
    }

    public function test_missing_migration_returns_a_safe_unconfigured_hub(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [],
            'error' => 'Function was not found in the schema cache',
            'status' => 404,
        ]);

        $hub = (new MathArcadeService($supabase))->hub($this->student());

        $this->assertFalse($hub['configured']);
        $this->assertTrue($hub['games'][0]['available']);
        $this->assertFalse($hub['games'][1]['available']);
    }

    private function rpcPayload(): array
    {
        return [
            'data' => [[
                'grade_level' => 4,
                'session' => [
                    'id' => self::SESSION_ID,
                    'student_id' => self::STUDENT_ID,
                    'game_key' => 'mental-arithmetic',
                    'status' => 'active',
                    'score' => 2,
                    'answers' => 3,
                    'correct_answers' => 2,
                    'streak' => 2,
                    'best_streak' => 2,
                    'sequence' => 4,
                    'challenge' => [
                        'prompt' => '(18 + 27) − 9',
                        'answer_type' => 'number',
                        'options' => [],
                        'answer' => '36',
                        'explanation' => 'Add first, then subtract.',
                    ],
                    'remaining_ms' => 45000,
                ],
                'personal' => ['best_score' => 3, 'best_streak' => 2, 'games_played' => 1, 'rank' => 4],
                'leaderboard' => [],
                'outcome' => ['direction' => 'started', 'correct' => false, 'finished' => false],
            ]],
            'error' => null,
            'status' => 200,
        ];
    }

    private function student(): array
    {
        return ['id' => self::STUDENT_ID, 'role' => 'student', 'grade_level' => 4];
    }
}
