<?php

namespace Tests\Feature;

use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class QuizLifecycleFlowTest extends TestCase
{
    private const TEACHER_ID = '110e8400-e29b-41d4-a716-446655440000';

    private const CLASS_ID = '330e8400-e29b-41d4-a716-446655440000';

    private const SESSION_ID = '440e8400-e29b-41d4-a716-446655440000';

    public function test_manual_start_succeeds_when_audit_delivery_is_unavailable(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminRpcResult')
                ->once()
                ->with('transition_quiz_session', [
                    'p_session_id' => self::SESSION_ID,
                    'p_class_id' => self::CLASS_ID,
                    'p_teacher_id' => self::TEACHER_ID,
                    'p_action' => 'start',
                ])
                ->andReturn([
                    'data' => [[
                        'outcome_code' => 'started',
                        'changed' => true,
                        'session_status' => 'active',
                        'quiz_topic' => 'Fractions',
                        'started_early' => false,
                    ]],
                    'error' => null,
                    'status' => 200,
                ]);
            $mock->shouldReceive('audit')
                ->once()
                ->andThrow(new \RuntimeException('Audit queue unavailable.'));
            $mock->shouldNotReceive('adminSelect');
            $mock->shouldNotReceive('update');
        });
        $this->mock(NotificationDeliveryService::class);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->postJson(
                '/teacher/classes/'.self::CLASS_ID.'/quizzes/'.self::SESSION_ID.'/start'
            );

        $response->assertOk()->assertJson([
            'success' => true,
            'changed' => true,
            'code' => 'started',
            'status' => 'active',
            'message' => 'Quiz started.',
        ]);
    }

    public function test_repeated_manual_end_is_successful_and_does_not_duplicate_the_audit(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminRpcResult')
                ->once()
                ->with('transition_quiz_session', [
                    'p_session_id' => self::SESSION_ID,
                    'p_class_id' => self::CLASS_ID,
                    'p_teacher_id' => self::TEACHER_ID,
                    'p_action' => 'end',
                ])
                ->andReturn([
                    'data' => [[
                        'outcome_code' => 'already_completed',
                        'changed' => false,
                        'session_status' => 'completed',
                    ]],
                    'error' => null,
                    'status' => 200,
                ]);
            $mock->shouldNotReceive('audit');
            $mock->shouldNotReceive('adminSelect');
            $mock->shouldNotReceive('update');
        });
        $this->mock(NotificationDeliveryService::class);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->postJson(
                '/teacher/classes/'.self::CLASS_ID.'/quizzes/'.self::SESSION_ID.'/end'
            );

        $response->assertOk()->assertJson([
            'success' => true,
            'changed' => false,
            'code' => 'already_completed',
            'status' => 'completed',
            'message' => 'This quiz has already ended.',
        ]);
    }

    public function test_database_connection_failure_returns_retryable_json_instead_of_false_not_found(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminRpcResult')
                ->once()
                ->andThrow(new \RuntimeException('Connection timed out.'));
            $mock->shouldNotReceive('adminSelect');
            $mock->shouldNotReceive('audit');
        });
        $this->mock(NotificationDeliveryService::class);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->postJson(
                '/teacher/classes/'.self::CLASS_ID.'/quizzes/'.self::SESSION_ID.'/start'
            );

        $response->assertStatus(503)->assertJson([
            'message' => 'MathVerse could not confirm the quiz status. Please try again.',
        ]);
    }

    public function test_results_offer_active_retake_only_to_students_with_a_finished_attempt(): void
    {
        $this->withoutMiddleware();
        $unfinishedId = '660e8400-e29b-41d4-a716-446655440000';
        $alreadyGrantedId = '770e8400-e29b-41d4-a716-446655440000';

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($unfinishedId, $alreadyGrantedId): void {
            $mock->shouldReceive('adminSelect')->times(3)->andReturnUsing(
                function (string $table) use ($unfinishedId, $alreadyGrantedId): array {
                    return match ($table) {
                        'quiz_sessions' => [[
                            'id' => self::SESSION_ID,
                            'status' => 'active',
                            'retake_mode' => false,
                        ]],
                        'quiz_results' => [
                            [
                                'student_id' => self::TEACHER_ID,
                                'correct_answers' => 4,
                                'total_questions' => 5,
                                'attempt_number' => 1,
                                'is_counted' => true,
                            ],
                            [
                                'student_id' => $alreadyGrantedId,
                                'correct_answers' => 3,
                                'total_questions' => 5,
                                'attempt_number' => 1,
                                'is_counted' => true,
                            ],
                        ],
                        default => [
                            [
                                'student_id' => self::TEACHER_ID,
                                'eligibility_status' => 'eligible',
                                'allowed_attempts' => 1,
                                'profiles' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
                            ],
                            [
                                'student_id' => $unfinishedId,
                                'eligibility_status' => 'eligible',
                                'allowed_attempts' => 1,
                                'profiles' => ['first_name' => 'Grace', 'last_name' => 'Hopper'],
                            ],
                            [
                                'student_id' => $alreadyGrantedId,
                                'eligibility_status' => 'eligible',
                                'allowed_attempts' => 2,
                                'profiles' => ['first_name' => 'Katherine', 'last_name' => 'Johnson'],
                            ],
                        ],
                    };
                },
            );
        });
        $this->mock(NotificationDeliveryService::class);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->getJson(
                '/teacher/classes/'.self::CLASS_ID.'/quizzes/'.self::SESSION_ID.'/results'
            );

        $response->assertOk();
        $rows = collect($response->json())->keyBy('student_id');
        $this->assertTrue($rows[self::TEACHER_ID]['can_grant_retake']);
        $this->assertFalse($rows[$unfinishedId]['can_grant_retake']);
        $this->assertFalse($rows[$alreadyGrantedId]['can_grant_retake']);
    }

    /** @return array{id: string, role: string, email: string} */
    private function teacher(): array
    {
        return [
            'id' => self::TEACHER_ID,
            'role' => 'teacher',
            'email' => 'teacher@example.test',
        ];
    }
}
