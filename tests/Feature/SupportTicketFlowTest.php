<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Tests\TestCase;

class SupportTicketFlowTest extends TestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';

    private const TEACHER = '22222222-2222-4222-8222-222222222222';

    private const ADMIN = '33333333-3333-4333-8333-333333333333';

    private const TICKET = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(SupabaseAuth::class);
    }

    public function test_student_can_submit_a_normalized_support_ticket(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminInsertResult')->once()->withArgs(function ($table, $data): bool {
            return $table === 'support_tickets'
                && $data['reporter_id'] === self::STUDENT
                && $data['reporter_role'] === 'student'
                && $data['reporter_name'] === 'Ada Learner'
                && $data['category'] === 'bug'
                && $data['subject'] === 'Quiz score does not increase'
                && $data['reference_id'] === 'MV-FF6753C9AA5EAB94'
                && $data['page_url'] === 'https://mathmetaverse.space/student/dashboard'
                && ! array_key_exists('status', $data)
                && ! array_key_exists('priority', $data);
        })->andReturn(['data' => [['id' => self::TICKET]], 'error' => null, 'status' => 201]);
        $database->shouldReceive('audit')->once()->withArgs(
            fn ($actor, $action, $type, $id, $metadata): bool => $actor['id'] === self::STUDENT
                && $action === 'support_ticket.created'
                && $type === 'support_ticket'
                && $id === self::TICKET
                && $metadata['category'] === 'bug'
        )->andReturn(true);

        $this->from('/support-tickets')->withSession(['supabase_user' => $this->student()])
            ->post('/support-tickets', [
                'category' => 'bug',
                'subject' => '  Quiz score does not increase  ',
                'description' => 'The correct answer was selected but the displayed score stayed at zero.',
                'page_url' => '  https://mathmetaverse.space/student/dashboard  ',
                'reference_id' => 'mv-ff6753c9aa5eab94',
            ])
            ->assertRedirect('/support-tickets/'.self::TICKET)
            ->assertSessionHas('success');
    }

    public function test_ticket_submission_rejects_unsafe_or_oversized_input_before_database_access(): void
    {
        $this->mock(SupabaseService::class)->shouldNotReceive('adminInsertResult');

        $this->from('/support-tickets')->withSession(['supabase_user' => $this->teacher()])
            ->post('/support-tickets', [
                'category' => 'security_override',
                'subject' => 'Bad',
                'description' => '<script>alert(1)</script>',
                'page_url' => 'javascript:alert(1)',
                'reference_id' => 'not-a-reference',
            ])
            ->assertRedirect('/support-tickets')
            ->assertSessionHasErrors(['category', 'subject', 'page_url', 'reference_id']);
    }

    public function test_ticket_submission_keeps_only_a_safe_mathverse_page_path(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminInsertResult')->once()->withArgs(
            fn ($table, $data): bool => $table === 'support_tickets'
                && $data['page_url'] === 'https://mathmetaverse.space/student/dashboard'
        )->andReturn(['data' => [['id' => self::TICKET]], 'error' => null, 'status' => 201]);
        $database->shouldReceive('audit')->once()->andReturn(true);

        $this->withSession(['supabase_user' => $this->student()])
            ->post('/support-tickets', [
                'category' => 'error',
                'subject' => 'Dashboard request failed',
                'description' => 'The dashboard showed a request unavailable reference after loading.',
                'page_url' => 'https://mathmetaverse.space/student/dashboard?token=private#section',
            ])
            ->assertRedirect('/support-tickets/'.self::TICKET);
    }

    public function test_ticket_submission_rejects_an_external_page_address(): void
    {
        $this->mock(SupabaseService::class)->shouldNotReceive('adminInsertResult');

        $this->from('/support-tickets')->withSession(['supabase_user' => $this->student()])
            ->post('/support-tickets', [
                'category' => 'bug',
                'subject' => 'Unexpected external page',
                'description' => 'This report tries to direct an administrator to another website.',
                'page_url' => 'https://example.test/fake-login',
            ])
            ->assertRedirect('/support-tickets')
            ->assertSessionHasErrors('page_url');
    }

    public function test_admin_cannot_use_the_student_or_teacher_submission_endpoint(): void
    {
        $this->mock(SupabaseService::class)->shouldNotReceive('adminInsertResult');

        $this->withSession(['supabase_user' => $this->admin()])
            ->post('/support-tickets', [
                'category' => 'bug',
                'subject' => 'This should not be accepted',
                'description' => 'Administrators manage tickets instead of submitting through this endpoint.',
            ])
            ->assertForbidden();
    }

    public function test_requester_ticket_list_is_scoped_to_the_signed_in_user(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectPage')->once()->with(
            'support_tickets',
            '*',
            [
                'reporter_id' => self::STUDENT,
                'order' => 'updated_at.desc,id.desc',
                'status' => 'in_progress',
            ],
            20,
            20,
        )->andReturn(['data' => [], 'total' => 22, 'error' => null]);

        $this->withSession(['supabase_user' => $this->student()])
            ->get('/support-tickets?status=in_progress&page=2')
            ->assertOk()
            ->assertViewHas('status', 'in_progress')
            ->assertViewHas('total', 22)
            ->assertViewHas('ticketsReady', true);
    }

    public function test_requester_cannot_read_another_users_ticket(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'support_tickets',
            '*',
            ['id' => self::TICKET, 'reporter_id' => self::STUDENT],
        )->andReturn(['data' => [], 'error' => null, 'status' => 200]);

        $this->withSession(['supabase_user' => $this->student()])
            ->get('/support-tickets/'.self::TICKET)
            ->assertRedirect('/support-tickets')
            ->assertSessionHas('error');
    }

    public function test_admin_can_filter_and_paginate_support_tickets(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectPage')->once()->with(
            'support_tickets',
            '*',
            [
                'order' => 'updated_at.desc,id.desc',
                'status' => 'open',
                'priority' => 'urgent',
            ],
            25,
            0,
        )->andReturn(['data' => [$this->ticket()], 'total' => 1, 'error' => null]);

        $this->withSession(['supabase_user' => $this->admin()])
            ->get('/admin/support-tickets?status=open&priority=urgent')
            ->assertOk()
            ->assertViewHas('tickets', fn ($tickets): bool => count($tickets) === 1)
            ->assertViewHas('status', 'open')
            ->assertViewHas('priority', 'urgent');
    }

    public function test_admin_updates_ticket_with_optimistic_lock_and_audits_the_change(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'support_tickets', '*', ['id' => self::TICKET]
        )->andReturn(['data' => [$this->ticket()], 'error' => null, 'status' => 200]);
        $database->shouldReceive('adminUpdate')->once()->withArgs(function ($table, $data, $filters): bool {
            return $table === 'support_tickets'
                && $filters === ['id' => self::TICKET, 'lock_version' => 1]
                && $data['status'] === 'resolved'
                && $data['priority'] === 'high'
                && $data['admin_response'] === 'The scoring service was repaired. Please retake the quiz.'
                && $data['assigned_to'] === self::ADMIN
                && $data['updated_by'] === self::ADMIN;
        })->andReturn([['id' => self::TICKET, 'lock_version' => 2]]);
        $database->shouldReceive('audit')->once()->andReturn(true);

        $this->withSession(['supabase_user' => $this->admin()])
            ->patch('/admin/support-tickets/'.self::TICKET, [
                'status' => 'resolved',
                'priority' => 'high',
                'admin_response' => ' The scoring service was repaired. Please retake the quiz. ',
                'lock_version' => 1,
            ])
            ->assertRedirect('/admin/support-tickets/'.self::TICKET)
            ->assertSessionHas('success');
    }

    public function test_admin_must_write_a_response_before_resolving_a_ticket(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()
            ->andReturn(['data' => [$this->ticket()], 'error' => null, 'status' => 200]);
        $database->shouldNotReceive('adminUpdate');

        $this->from('/admin/support-tickets/'.self::TICKET)
            ->withSession(['supabase_user' => $this->admin()])
            ->patch('/admin/support-tickets/'.self::TICKET, [
                'status' => 'resolved',
                'priority' => 'normal',
                'admin_response' => '   ',
                'lock_version' => 1,
            ])
            ->assertRedirect('/admin/support-tickets/'.self::TICKET)
            ->assertSessionHasErrors('admin_response');
    }

    /** @return array<string, mixed> */
    private function student(): array
    {
        return [
            'id' => self::STUDENT,
            'role' => 'student',
            'first_name' => 'Ada',
            'last_name' => 'Learner',
            'email' => 'ada@example.test',
        ];
    }

    /** @return array<string, mixed> */
    private function teacher(): array
    {
        return [
            'id' => self::TEACHER,
            'role' => 'teacher',
            'first_name' => 'Tess',
            'last_name' => 'Teacher',
            'email' => 'tess@example.test',
        ];
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return [
            'id' => self::ADMIN,
            'role' => 'admin',
            'first_name' => 'Ari',
            'last_name' => 'Admin',
            'email' => 'ari@example.test',
        ];
    }

    /** @return array<string, mixed> */
    private function ticket(): array
    {
        return [
            'id' => self::TICKET,
            'reporter_id' => self::STUDENT,
            'reporter_role' => 'student',
            'reporter_name' => 'Ada Learner',
            'reporter_email' => 'ada@example.test',
            'category' => 'bug',
            'subject' => 'Quiz score does not increase',
            'description' => 'The correct answer was selected but the displayed score stayed at zero.',
            'page_url' => 'https://mathmetaverse.space/student/dashboard',
            'reference_id' => 'MV-FF6753C9AA5EAB94',
            'status' => 'open',
            'priority' => 'normal',
            'admin_response' => null,
            'assigned_to' => null,
            'updated_by' => null,
            'lock_version' => 1,
            'resolved_at' => null,
            'created_at' => '2026-10-03T00:00:00+00:00',
            'updated_at' => '2026-10-03T00:00:00+00:00',
        ];
    }
}
