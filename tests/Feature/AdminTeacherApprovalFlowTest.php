<?php

namespace Tests\Feature;

use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminTeacherApprovalFlowTest extends TestCase
{
    private const TEACHER_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const AUDIT_ID = '660e8400-e29b-41d4-a716-446655440000';

    public function test_approval_sends_its_email_during_the_request(): void
    {
        $this->withoutMiddleware();
        $profile = $this->pendingTeacher();

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with(
                    'profiles',
                    'id,role,first_name,last_name,email',
                    ['id' => self::TEACHER_ID, 'deactivated_at' => ['operator' => 'is', 'value' => 'null']]
                )
                ->andReturn([$profile]);
            $mock->shouldReceive('adminUpdate')
                ->once()
                ->with(
                    'profiles',
                    ['role' => 'teacher'],
                    ['id' => self::TEACHER_ID, 'role' => 'pending_teacher', 'deactivated_at' => ['operator' => 'is', 'value' => 'null']]
                )
                ->andReturn([['id' => self::TEACHER_ID]]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturn(self::AUDIT_ID);
            $mock->shouldReceive('completePrivilegedAudit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('isReady')->once()->andReturn(true);
            $mock->shouldReceive('emailConfigurationIssue')->once()->andReturnNull();
            $mock->shouldReceive('deliverTeacherApprovalEmailNow')
                ->once()
                ->with($profile)
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->withSession([
            'supabase_user' => ['id' => 'admin-id', 'role' => 'admin'],
        ])->post('/admin/approve-teacher/' . self::TEACHER_ID);

        $response->assertRedirect('/admin/dashboard?section=role-verify');
        $response->assertSessionHas(
            'success',
            'Teacher approved. The approval email was sent.'
        );
    }

    public function test_approval_reports_when_immediate_mail_fails_and_keeps_the_retry(): void
    {
        $this->withoutMiddleware();
        $profile = $this->pendingTeacher();

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([$profile]);
            $mock->shouldReceive('adminUpdate')->once()->andReturn([['id' => self::TEACHER_ID]]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturn(self::AUDIT_ID);
            $mock->shouldReceive('completePrivilegedAudit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('isReady')->once()->andReturn(true);
            $mock->shouldReceive('emailConfigurationIssue')->once()->andReturnNull();
            $mock->shouldReceive('deliverTeacherApprovalEmailNow')
                ->once()
                ->with($profile)
                ->andReturn(['sent' => false, 'queued' => true]);
        });

        $response = $this->withSession([
            'supabase_user' => ['id' => 'admin-id', 'role' => 'admin'],
        ])->post('/admin/approve-teacher/' . self::TEACHER_ID);

        $response->assertRedirect('/admin/dashboard?section=role-verify');
        $response->assertSessionHas(
            'error',
            'Teacher approved, but the mail server did not accept the approval email immediately. MathVerse will retry it automatically.'
        );
    }

    public function test_approval_is_stopped_when_production_email_is_unavailable(): void
    {
        $this->withoutMiddleware();
        $profile = $this->pendingTeacher();

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([$profile]);
            $mock->shouldNotReceive('adminUpdate');
            $mock->shouldNotReceive('beginPrivilegedAudit');
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isReady')->once()->andReturn(true);
            $mock->shouldReceive('emailConfigurationIssue')
                ->once()
                ->andReturn('MathVerse event email is not connected to a real mail provider.');
        });

        $response = $this->post('/admin/approve-teacher/' . self::TEACHER_ID);

        $response->assertRedirect('/admin/dashboard?section=role-verify');
        $response->assertSessionHas(
            'error',
            'The teacher was not approved because the decision email is unavailable. MathVerse event email is not connected to a real mail provider.'
        );
    }

    public function test_approval_is_blocked_before_role_change_when_durable_audit_is_unavailable(): void
    {
        $this->withoutMiddleware();
        $profile = $this->pendingTeacher();

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([$profile]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturnNull();
            $mock->shouldNotReceive('adminUpdate');
            $mock->shouldNotReceive('completePrivilegedAudit');
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isReady')->once()->andReturn(true);
            $mock->shouldReceive('emailConfigurationIssue')->once()->andReturnNull();
            $mock->shouldNotReceive('deliverTeacherApprovalEmailNow');
        });

        $response = $this->withSession(['supabase_user' => ['id' => 'admin-id', 'role' => 'admin']])
            ->post('/admin/approve-teacher/'.self::TEACHER_ID);
        $response->assertRedirect('/admin/dashboard?section=role-verify');
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'secure audit trail'));
    }

    public function test_rejection_sends_its_email_during_the_request(): void
    {
        $this->withoutMiddleware();
        $profile = $this->pendingTeacher();

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([$profile]);
            $mock->shouldNotReceive('deleteAuthUser');
            $mock->shouldReceive('adminRpcResult')
                ->once()
                ->with('set_account_deactivated', ['p_actor_id' => 'admin-id', 'p_id' => self::TEACHER_ID, 'p_restore' => false])
                ->andReturn(['error' => null, 'data' => [['id' => self::TEACHER_ID]]]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturn(self::AUDIT_ID);
            $mock->shouldReceive('completePrivilegedAudit')->once()->andReturn(true);
        });
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('isReady')->once()->andReturn(true);
            $mock->shouldReceive('emailConfigurationIssue')->once()->andReturnNull();
            $mock->shouldReceive('deliverStandaloneEmailNow')
                ->once()
                ->withArgs(fn (
                    string $eventType,
                    string $recipientEmail,
                    string $recipientName,
                    string $title,
                    string $message,
                    ?string $actionUrl,
                    array $data,
                    string $deliveryKey,
                ): bool => $eventType === 'teacher_denied'
                    && $recipientEmail === $profile['email']
                    && $recipientName === 'Ada Lovelace'
                    && $actionUrl === '/'
                    && $deliveryKey === 'teacher-denied:' . self::TEACHER_ID
                )
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->withSession([
            'supabase_user' => ['id' => 'admin-id', 'role' => 'admin'],
        ])->delete('/admin/deny-teacher/' . self::TEACHER_ID);

        $response->assertRedirect('/admin/dashboard?section=role-verify');
        $response->assertSessionHas(
            'success',
            'Application rejected. The decision email was sent.'
        );
    }

    /** @return array<string, string> */
    private function pendingTeacher(): array
    {
        return [
            'id' => self::TEACHER_ID,
            'role' => 'pending_teacher',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ];
    }
}
