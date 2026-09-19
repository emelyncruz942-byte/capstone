<?php

namespace Tests\Feature;

use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminImmediateAccountEmailFlowTest extends TestCase
{
    private const ADMIN_ID = '110e8400-e29b-41d4-a716-446655440000';
    private const USER_ID = '220e8400-e29b-41d4-a716-446655440000';
    private const AUDIT_ID = '330e8400-e29b-41d4-a716-446655440000';

    public function test_suspension_sends_the_status_email_during_the_request(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([[
                'id' => self::USER_ID,
                'role' => 'student',
                'email' => 'student@example.test',
                'suspended_at' => null,
            ]]);
            $mock->shouldReceive('setAuthUserSuspended')
                ->once()
                ->with(self::USER_ID, true)
                ->andReturn(true);
            $mock->shouldReceive('adminUpdate')
                ->once()
                ->andReturn([['id' => self::USER_ID]]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturn(self::AUDIT_ID);
            $mock->shouldReceive('completePrivilegedAudit')->once()->andReturn(true);
        });
        $this->expectImmediateStatusEmail('account_suspended');

        $response = $this->withSession(['supabase_user' => $this->admin()])
            ->post('/admin/user/' . self::USER_ID . '/suspend', [
                'reason' => 'Repeated misuse.',
                'return_section' => 'students',
            ]);

        $response->assertRedirect('/admin/dashboard?section=students')
            ->assertSessionHas(
                'success',
                'Account suspended. Its data was preserved, and the status email was sent.'
            );
    }

    public function test_restoration_sends_the_status_email_during_the_request(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')->once()->andReturn([[
                'id' => self::USER_ID,
                'role' => 'student',
                'email' => 'student@example.test',
                'suspended_at' => '2026-09-09T08:00:00+00:00',
            ]]);
            $mock->shouldReceive('setAuthUserSuspended')
                ->once()
                ->with(self::USER_ID, false)
                ->andReturn(true);
            $mock->shouldReceive('adminUpdate')
                ->once()
                ->andReturn([['id' => self::USER_ID]]);
            $mock->shouldReceive('beginPrivilegedAudit')->once()->andReturn(self::AUDIT_ID);
            $mock->shouldReceive('completePrivilegedAudit')->once()->andReturn(true);
        });
        $this->expectImmediateStatusEmail('account_restored');

        $response = $this->withSession(['supabase_user' => $this->admin()])
            ->post('/admin/user/' . self::USER_ID . '/restore', [
                'return_section' => 'students',
            ]);

        $response->assertRedirect('/admin/dashboard?section=students')
            ->assertSessionHas(
                'success',
                'Account restored. The status email was sent.'
            );
    }

    private function expectImmediateStatusEmail(string $eventType): void
    {
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock) use ($eventType): void {
            $mock->shouldReceive('deliverNotificationEmailNow')
                ->once()
                ->withArgs(fn (...$arguments): bool => count($arguments) === 4
                    && $arguments[0] === self::USER_ID
                    && $arguments[1] === $eventType
                    && $arguments[2] === null
                    && is_string($arguments[3])
                )
                ->andReturn(['sent' => true, 'queued' => true]);
        });
    }

    /** @return array{id: string, role: string, email: string} */
    private function admin(): array
    {
        return [
            'id' => self::ADMIN_ID,
            'role' => 'admin',
            'email' => 'admin@example.test',
        ];
    }
}
