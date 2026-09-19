<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Mockery;
use Tests\TestCase;

class HorizontalAuthorizationTest extends TestCase
{
    private const ACTOR_ID = '11111111-1111-4111-8111-111111111111';
    private const TARGET_ID = '22222222-2222-4222-8222-222222222222';
    private const SESSION_ID = '33333333-3333-4333-8333-333333333333';

    public function test_teacher_cannot_open_another_teachers_class(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('classes', '*', [
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([]);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->get('/teacher/classes/' . self::TARGET_ID);

        $response->assertRedirect('/teacher/dashboard?section=classes');
        $response->assertSessionHas('error', 'Class not found.');
    }

    public function test_teacher_class_delete_uses_the_owner_checked_database_transaction(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('classes', '*', [
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([[
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
                'class_name' => 'Section A',
            ]]);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('set_recovery_item', [
                'p_actor_id' => self::ACTOR_ID, 'p_kind' => 'class',
                'p_id' => self::TARGET_ID, 'p_restore' => false,
            ])
            ->andReturn([
                'data' => [['id' => self::TARGET_ID]],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldNotReceive('audit'); // the mutation and audit commit together in SQL

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->delete('/teacher/classes/' . self::TARGET_ID, ['delete_class_id' => self::TARGET_ID]);

        $response->assertRedirect('/teacher/trash');
        $response->assertSessionHas('success', 'Class moved to Trash. Students, assignments and results are preserved.');
    }

    public function test_misdirected_child_deletes_cannot_delete_a_class(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->twice()
            ->with('classes', '*', ['id' => self::TARGET_ID, 'teacher_id' => self::ACTOR_ID])
            ->andReturn([['id' => self::TARGET_ID, 'teacher_id' => self::ACTOR_ID]]);
        $supabase->shouldNotReceive('adminRpcResult');
        $supabase->shouldNotReceive('adminDelete');
        $supabase->shouldNotReceive('audit');

        foreach ([[], ['delete_class_id' => self::ACTOR_ID]] as $fields) {
            $this->withSession(['supabase_user' => $this->teacher()])
                ->post('/teacher/classes/' . self::TARGET_ID, ['_method' => 'DELETE'] + $fields)
                ->assertRedirect('/teacher/classes/' . self::TARGET_ID . '/settings')
                ->assertSessionHas('error', 'Class deletion was not confirmed. No class data was removed.');
        }
    }

    public function test_assignment_delete_only_calls_the_owned_child_transaction(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')->once()
            ->with('quiz_sessions', '*', [
                'id' => self::SESSION_ID, 'class_id' => self::TARGET_ID, 'teacher_id' => self::ACTOR_ID,
            ])->andReturn([[
                'id' => self::SESSION_ID, 'class_id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID, 'status' => 'waiting',
            ]]);
        $supabase->shouldReceive('adminRpcResult')->once()
            ->with('delete_open_quiz_assignment', [
                'p_teacher_id' => self::ACTOR_ID, 'p_class_id' => self::TARGET_ID,
                'p_session_id' => self::SESSION_ID,
            ])->andReturn(['data' => [['was_shared_assignment' => false, 'remaining_usage_count' => 0]], 'error' => null]);
        $supabase->shouldNotReceive('adminDelete');
        $supabase->shouldReceive('audit')->once();

        $this->withSession(['supabase_user' => $this->teacher()])
            ->post('/teacher/classes/' . self::TARGET_ID . '/quizzes/' . self::SESSION_ID, ['_method' => 'DELETE'])
            ->assertRedirect('/teacher/classes/' . self::TARGET_ID)
            ->assertSessionHas('success', "Assignment deleted. Your quiz's Class Uses were not changed.");
    }

    public function test_assignment_delete_cannot_mutate_another_teachers_session(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')->once()
            ->with('quiz_sessions', '*', [
                'id' => self::SESSION_ID, 'class_id' => self::TARGET_ID, 'teacher_id' => self::ACTOR_ID,
            ])->andReturn([]);
        $supabase->shouldNotReceive('adminRpcResult');
        $supabase->shouldNotReceive('adminDelete');
        $supabase->shouldNotReceive('audit');

        $this->withSession(['supabase_user' => $this->teacher()])
            ->delete('/teacher/classes/' . self::TARGET_ID . '/quizzes/' . self::SESSION_ID)
            ->assertRedirect('/teacher/dashboard?section=classes')
            ->assertSessionHas('error', 'Quiz assignment not found.');
    }

    public function test_teacher_quiz_json_requires_ownership(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('quizzes', '*', [
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([]);

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->getJson('/teacher/quizzes/' . self::TARGET_ID);

        $response->assertNotFound()->assertJson([
            'message' => 'Quiz not found.',
        ]);
    }

    public function test_teacher_quiz_delete_scopes_the_mutation_to_its_owner(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('quizzes', '*', [
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([[
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
                'topic' => 'Fractions',
                'visibility' => 'private',
            ]]);
        $supabase->shouldNotReceive('delete');
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->with('set_recovery_item', [
                'p_actor_id' => self::ACTOR_ID, 'p_kind' => 'quiz',
                'p_id' => self::TARGET_ID, 'p_restore' => false,
            ])->andReturn(['error' => null, 'data' => [['id' => self::TARGET_ID]]]);
        $supabase->shouldNotReceive('audit');

        $response = $this->withSession([
            'supabase_user' => $this->teacher(),
            'supabase_token' => 'access-token',
        ])
            ->delete('/teacher/quizzes/' . self::TARGET_ID);

        $response->assertRedirect('/teacher/trash');
        $response->assertSessionHas('success');
    }

    public function test_starting_a_quiz_rechecks_owner_class_and_waiting_state_in_the_write(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('quiz_sessions', '*', [
                'id' => self::SESSION_ID,
                'class_id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([[
                'id' => self::SESSION_ID,
                'class_id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
                'status' => 'waiting',
                'available_at' => null,
                'due_at' => null,
                'topic' => 'Fractions',
            ]]);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('classes', '*', [
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
            ])
            ->andReturn([[
                'id' => self::TARGET_ID,
                'teacher_id' => self::ACTOR_ID,
                'archived_at' => null,
            ]]);
        $supabase->shouldReceive('update')
            ->once()
            ->with(
                'quiz_sessions',
                Mockery::on(fn (array $data): bool => ($data['status'] ?? null) === 'active'
                    && ($data['is_active'] ?? null) === true
                    && isset($data['available_at'], $data['started_at'])),
                [
                    'id' => self::SESSION_ID,
                    'class_id' => self::TARGET_ID,
                    'teacher_id' => self::ACTOR_ID,
                    'status' => 'waiting',
                ],
                'access-token'
            )
            ->andReturn([['id' => self::SESSION_ID]]);
        $supabase->shouldReceive('audit')->once();

        $response = $this->withSession([
            'supabase_user' => $this->teacher(),
            'supabase_token' => 'access-token',
        ])->postJson(
            '/teacher/classes/' . self::TARGET_ID . '/quizzes/' . self::SESSION_ID . '/start'
        );

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_student_cannot_open_a_class_without_membership(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('class_members', 'student_id,joined_at', [
                'class_id' => self::TARGET_ID,
                'student_id' => self::ACTOR_ID,
            ])
            ->andReturn([]);

        $response = $this->withSession(['supabase_user' => $this->student()])
            ->get('/student/classes/' . self::TARGET_ID);

        $response->assertRedirect('/student/dashboard?section=class');
        $response->assertSessionHas('error', 'You do not have access to that class.');
    }

    public function test_notification_action_requires_both_id_and_owner(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('notifications', 'id,action_url,read_at', [
                'id' => self::TARGET_ID,
                'user_id' => self::ACTOR_ID,
            ])
            ->andReturn([]);
        $supabase->shouldNotReceive('adminUpdate');

        $response = $this->withSession(['supabase_user' => $this->student()])
            ->from('/student/dashboard')
            ->post('/notifications/' . self::TARGET_ID . '/read');

        $response->assertRedirect('/student/dashboard');
        $response->assertSessionHas('error', 'That notification is no longer available.');
    }

    public function test_role_middleware_redirects_a_student_away_from_teacher_routes(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with(
                'profiles',
                'id,role,first_name,last_name,email,avatar_url,grade_level,suspended_at,leaderboard_alias,show_on_leaderboard,auth_sessions_invalid_before',
                ['id' => self::ACTOR_ID]
            )
            ->andReturn([array_merge($this->student(), [
                'suspended_at' => null,
                'auth_sessions_invalid_before' => null,
            ])]);

        $response = $this->withSession([
            'supabase_user' => $this->student(),
            'supabase_authenticated_at' => '2026-09-06T08:00:00+00:00',
        ])->get('/teacher/dashboard');

        $response->assertRedirect('/student/dashboard');
    }

    public function test_an_unsupported_profile_role_invalidates_the_existing_session(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->andReturn([array_merge($this->student(), [
                'role' => 'pending_teacher',
                'suspended_at' => null,
                'auth_sessions_invalid_before' => null,
            ])]);

        $response = $this->withSession([
            'supabase_user' => $this->student(),
            'supabase_token' => 'access-token',
            'supabase_authenticated_at' => '2026-09-06T08:00:00+00:00',
        ])->get('/student/dashboard');

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionHas(
            'error',
            'Your account is not authorized to use a dashboard. Contact an administrator.'
        );
    }

    public function test_suspension_invalidates_an_existing_application_session(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->andReturn([array_merge($this->student(), [
                'suspended_at' => '2026-09-06T08:30:00+00:00',
                'auth_sessions_invalid_before' => null,
            ])]);

        $response = $this->withSession([
            'supabase_user' => $this->student(),
            'supabase_token' => 'access-token',
            'supabase_authenticated_at' => '2026-09-06T08:00:00+00:00',
        ])->get('/student/dashboard');

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionMissing('supabase_token');
    }

    public function test_password_change_invalidates_an_older_application_session(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->andReturn([array_merge($this->student(), [
                'suspended_at' => null,
                'auth_sessions_invalid_before' => '2026-09-06T08:30:00+00:00',
            ])]);

        $response = $this->withSession([
            'supabase_user' => $this->student(),
            'supabase_token' => 'access-token',
            'supabase_authenticated_at' => '2026-09-06T08:00:00+00:00',
        ])->get('/student/dashboard');

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionHas('error', 'Your password changed. Please sign in again.');
    }

    /** @return array{id: string, role: string, email: string} */
    private function teacher(): array
    {
        return [
            'id' => self::ACTOR_ID,
            'role' => 'teacher',
            'email' => 'teacher@example.com',
        ];
    }

    /** @return array{id: string, role: string, email: string} */
    private function student(): array
    {
        return [
            'id' => self::ACTOR_ID,
            'role' => 'student',
            'email' => 'student@example.com',
        ];
    }
}
