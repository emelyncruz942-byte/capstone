<?php

namespace Tests\Feature;

use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class TeacherImmediateNotificationFlowTest extends TestCase
{
    private const TEACHER_ID = '110e8400-e29b-41d4-a716-446655440000';
    private const STUDENT_ID = '220e8400-e29b-41d4-a716-446655440000';
    private const CLASS_ID = '330e8400-e29b-41d4-a716-446655440000';
    private const SESSION_ID = '440e8400-e29b-41d4-a716-446655440000';

    public function test_granting_a_retake_sends_the_student_email_during_the_request(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('quiz_sessions', '*', [
                    'id' => self::SESSION_ID,
                    'class_id' => self::CLASS_ID,
                    'teacher_id' => self::TEACHER_ID,
                ])
                ->andReturn([['id' => self::SESSION_ID, 'status' => 'completed']]);
            $mock->shouldReceive('adminRpc')
                ->once()
                ->withArgs(fn (string $function, array $arguments): bool =>
                    $function === 'grant_quiz_retake'
                    && ($arguments['p_student_id'] ?? null) === self::STUDENT_ID
                )
                ->andReturn([['new_allowed_attempts' => 2, 'retake_due_at' => null]]);
            $mock->shouldReceive('audit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliverNotificationEmailNow')
                ->once()
                ->with(
                    self::STUDENT_ID,
                    'quiz_retake_granted',
                    'quiz-retake:' . self::SESSION_ID . ':' . self::STUDENT_ID . ':2',
                )
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->postJson(
                '/teacher/classes/' . self::CLASS_ID . '/quizzes/' . self::SESSION_ID
                    . '/students/' . self::STUDENT_ID . '/retake',
                ['reason' => 'Connection failed.'],
            );

        $response->assertOk()->assertJson([
            'success' => true,
            'email_sent' => true,
            'message' => 'Retake granted. The student email was sent.',
        ]);
    }

    public function test_excusing_a_student_sends_the_student_email_during_the_request(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')->twice()->andReturnUsing(
                fn (string $table): array => $table === 'quiz_sessions'
                    ? [['id' => self::SESSION_ID, 'status' => 'completed']]
                    : [],
            );
            $mock->shouldReceive('adminUpdate')
                ->once()
                ->andReturn([['student_id' => self::STUDENT_ID]]);
            $mock->shouldReceive('audit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliverNotificationEmailNow')
                ->once()
                ->with(
                    self::STUDENT_ID,
                    'quiz_excused',
                    'quiz-excused:' . self::SESSION_ID . ':' . self::STUDENT_ID,
                )
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->postJson(
                '/teacher/classes/' . self::CLASS_ID . '/quizzes/' . self::SESSION_ID
                    . '/students/' . self::STUDENT_ID . '/excuse',
                ['reason' => 'Approved absence.'],
            );

        $response->assertOk()->assertJson([
            'success' => true,
            'email_sent' => true,
            'message' => 'Student marked excused. The student email was sent.',
        ]);
    }

    public function test_removing_a_student_sends_the_removal_email_during_the_request(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('classes', '*', ['id' => self::CLASS_ID, 'teacher_id' => self::TEACHER_ID])
                ->andReturn([['id' => self::CLASS_ID, 'teacher_id' => self::TEACHER_ID]]);
            $mock->shouldReceive('adminDelete')->once()
                ->with('class_members', ['class_id' => self::CLASS_ID, 'student_id' => self::STUDENT_ID])
                ->andReturn(true);
            $mock->shouldReceive('adminUpdate')->once()
                ->with('profiles', ['class_id' => null], ['id' => self::STUDENT_ID, 'class_id' => self::CLASS_ID])
                ->andReturn([]);
            $mock->shouldNotReceive('adminRpcResult');
            $mock->shouldReceive('audit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliverNotificationEmailNow')
                ->once()
                ->withArgs(fn (
                    string $userId,
                    string $eventType,
                    ?string $dedupeKey,
                    ?string $createdAfter,
                ): bool => $userId === self::STUDENT_ID
                    && $eventType === 'removed_from_class'
                    && $dedupeKey === null
                    && is_string($createdAfter)
                )
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->delete('/teacher/classes/' . self::CLASS_ID . '/students/' . self::STUDENT_ID);

        $response->assertRedirect('/teacher/classes/' . self::CLASS_ID)
            ->assertSessionHas(
                'success',
                'Student removed from the class. The removal email was sent.'
            );
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
