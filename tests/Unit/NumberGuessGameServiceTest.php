<?php

namespace Tests\Unit;

use App\Services\NumberGuessGameService;
use App\Services\SupabaseService;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NumberGuessGameServiceTest extends TestCase
{
    private const STUDENT_ID = '11111111-1111-4111-8111-111111111111';
    private const SESSION_ID = '22222222-2222-4222-8222-222222222222';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dashboard_allows_only_safe_session_fields(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('number_guess_dashboard', [
                'p_student_id' => self::STUDENT_ID,
                'p_limit' => 10,
            ])
            ->andReturn([
                'data' => [[
                    'grade_level' => 4,
                    'session' => [
                        'id' => self::SESSION_ID,
                        'status' => 'active',
                        'score' => 2,
                        'guesses' => 7,
                        'range_max' => 200,
                        'remaining_ms' => 45000,
                        'target_number' => 173,
                    ],
                    'personal' => ['best_score' => 2, 'games_played' => 1],
                    'leaderboard' => [],
                ]],
                'error' => null,
                'status' => 200,
            ]);

        $state = (new NumberGuessGameService($supabase))->dashboard($this->student());

        $this->assertTrue($state['configured']);
        $this->assertSame(4, $state['grade']);
        $this->assertSame(2, $state['session']['score']);
        $this->assertSame(200, $state['session']['range_max']);
        $this->assertArrayNotHasKey('target_number', $state['session']);
    }

    public function test_missing_database_function_produces_a_safe_unconfigured_state(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [],
            'error' => 'Function was not found in the schema cache',
            'status' => 404,
        ]);

        $state = (new NumberGuessGameService($supabase))->dashboard($this->student());

        $this->assertFalse($state['configured']);
        $this->assertNull($state['session']);
        $this->assertSame([], $state['leaderboard']);
        $this->assertStringContainsString('database update', $state['message']);
    }

    public function test_database_range_error_is_returned_as_a_friendly_message(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('submit_number_guess', [
                'p_session_id' => self::SESSION_ID,
                'p_student_id' => self::STUDENT_ID,
                'p_expected_guesses' => 2,
                'p_guess' => 151,
            ])
            ->andReturn([
                'data' => [],
                'error' => 'P0001: Enter a number from 1 to 150',
                'status' => 400,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Enter a number from 1 to 150.');

        (new NumberGuessGameService($supabase))->guess(
            $this->student(),
            self::SESSION_ID,
            151,
            2
        );
    }

    public function test_finished_session_reveals_only_the_final_number(): void
    {
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [[
                'session' => [
                    'id' => self::SESSION_ID,
                    'status' => 'expired',
                    'score' => 3,
                    'guesses' => 12,
                    'range_max' => 250,
                    'remaining_ms' => 0,
                    'target_number' => 199,
                ],
                'personal' => ['best_score' => 3, 'best_guesses' => 12, 'games_played' => 1],
                'outcome' => [
                    'direction' => 'expired',
                    'correct' => false,
                    'finished' => true,
                    'correct_number' => 199,
                ],
            ]],
            'error' => null,
            'status' => 200,
        ]);

        $result = (new NumberGuessGameService($supabase))->finish($this->student(), self::SESSION_ID);

        $this->assertArrayNotHasKey('target_number', $result['session']);
        $this->assertSame(199, $result['outcome']['correct_number']);
    }

    private function student(): array
    {
        return ['id' => self::STUDENT_ID, 'role' => 'student'];
    }
}
