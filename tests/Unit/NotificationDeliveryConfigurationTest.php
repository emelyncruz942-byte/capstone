<?php

namespace Tests\Unit;

use App\Mail\MathVerseEventMail;
use App\Services\AdminPushService;
use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class NotificationDeliveryConfigurationTest extends TestCase
{
    public function test_production_rejects_a_log_only_mailer(): void
    {
        $this->useProductionEnvironment();
        config([
            'mail.default' => 'log',
            'mail.from.address' => 'notifications@mathmetaverse.space',
        ]);

        $this->assertNotNull($this->service()->emailConfigurationIssue());
    }

    public function test_production_accepts_an_external_smtp_mailer(): void
    {
        $this->useProductionEnvironment();
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'notifications@mathmetaverse.space',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.mail-provider.test',
                'port' => 587,
            ],
        ]);

        $this->assertNull($this->service()->emailConfigurationIssue());
    }

    public function test_production_rejects_a_failover_that_can_silently_log_mail(): void
    {
        $this->useProductionEnvironment();
        config([
            'mail.default' => 'failover',
            'mail.from.address' => 'notifications@mathmetaverse.space',
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'log'],
            ],
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.mail-provider.test',
            ],
            'mail.mailers.log' => ['transport' => 'log'],
        ]);

        $this->assertNotNull($this->service()->emailConfigurationIssue());
    }

    public function test_teacher_approval_email_is_claimed_and_sent_immediately(): void
    {
        Mail::fake();
        config(['app.url' => 'https://mathmetaverse.space']);

        $profile = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ];
        $delivery = [
            'id' => '660e8400-e29b-41d4-a716-446655440000',
            'notification_id' => '770e8400-e29b-41d4-a716-446655440000',
            'user_id' => $profile['id'],
            'channel' => 'email',
            'event_type' => 'teacher_approved',
            'recipient_email' => $profile['email'],
            'recipient_name' => 'Ada Lovelace',
            'title' => 'Teacher account approved',
            'message' => 'Your MathVerse teacher application was approved.',
            'action_url' => '/teacher/dashboard',
            'data' => [],
            'delivery_key' => 'notification:approval:email',
            'status' => 'pending',
            'attempts' => 0,
        ];

        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->withArgs(fn (string $table, string $columns, array $filters): bool =>
                $table === 'notification_deliveries'
                && $columns === 'id,status'
                && $filters['user_id'] === $profile['id']
            )
            ->andReturn([
                'data' => [['id' => $delivery['id'], 'status' => 'pending']],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->withArgs(fn (string $table, string $columns, array $filters): bool =>
                $table === 'notification_deliveries'
                && str_contains($columns, 'recipient_email')
                && $filters['user_id'] === $profile['id']
            )
            ->andReturn(['data' => [$delivery], 'error' => null, 'status' => 200]);
        $supabase->shouldReceive('adminUpdate')
            ->once()
            ->withArgs(fn (string $table, array $data, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($data['status'] ?? null) === 'sending'
                && ($data['attempts'] ?? null) === 1
                && ($filters['id'] ?? null) === $delivery['id']
            )
            ->andReturnUsing(fn (string $table, array $data): array => [array_merge($delivery, $data)]);
        $supabase->shouldReceive('adminUpdate')
            ->once()
            ->withArgs(fn (string $table, array $data, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($data['status'] ?? null) === 'sent'
                && ($filters['id'] ?? null) === $delivery['id']
                && !empty($filters['locked_by'])
            )
            ->andReturn([['id' => $delivery['id']]]);

        $service = new NotificationDeliveryService(
            $supabase,
            Mockery::mock(AdminPushService::class),
        );

        $this->assertSame(
            ['sent' => true, 'queued' => true],
            $service->deliverTeacherApprovalEmailNow($profile)
        );
        Mail::assertSent(
            MathVerseEventMail::class,
            fn (MathVerseEventMail $mail): bool => $mail->hasTo($profile['email'])
        );
    }

    public function test_triggered_notification_email_is_claimed_and_sent_immediately(): void
    {
        Mail::fake();
        config(['app.url' => 'https://mathmetaverse.space']);

        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $notificationId = '770e8400-e29b-41d4-a716-446655440000';
        $delivery = [
            'id' => '660e8400-e29b-41d4-a716-446655440000',
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'channel' => 'email',
            'event_type' => 'quiz_retake_granted',
            'recipient_email' => 'student@example.test',
            'recipient_name' => 'Ada Student',
            'title' => 'Quiz retake granted',
            'message' => 'You can attempt the quiz again.',
            'action_url' => '/student/classes/880e8400-e29b-41d4-a716-446655440000',
            'data' => [],
            'delivery_key' => 'notification:retake:email',
            'status' => 'pending',
            'attempts' => 0,
        ];
        $dedupeKey = 'quiz-retake:880e8400-e29b-41d4-a716-446655440000:' . $userId . ':2';

        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->withArgs(fn (string $table, string $columns, array $filters): bool =>
                $table === 'notifications'
                && $columns === 'id'
                && ($filters['user_id'] ?? null) === $userId
                && ($filters['type'] ?? null) === 'quiz_retake_granted'
                && ($filters['dedupe_key'] ?? null) === $dedupeKey
            )
            ->andReturn(['data' => [['id' => $notificationId]], 'error' => null, 'status' => 200]);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->withArgs(fn (string $table, string $columns, array $filters): bool =>
                $table === 'notification_deliveries'
                && str_contains($columns, 'recipient_email')
                && ($filters['notification_id'] ?? null) === $notificationId
            )
            ->andReturn(['data' => [$delivery], 'error' => null, 'status' => 200]);
        $supabase->shouldReceive('adminUpdate')
            ->once()
            ->withArgs(fn (string $table, array $data, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($data['status'] ?? null) === 'sending'
                && ($filters['id'] ?? null) === $delivery['id']
            )
            ->andReturnUsing(fn (string $table, array $data): array => [array_merge($delivery, $data)]);
        $supabase->shouldReceive('adminUpdate')
            ->once()
            ->withArgs(fn (string $table, array $data, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($data['status'] ?? null) === 'sent'
                && ($filters['id'] ?? null) === $delivery['id']
            )
            ->andReturn([['id' => $delivery['id']]]);

        $service = new NotificationDeliveryService(
            $supabase,
            Mockery::mock(AdminPushService::class),
        );

        $this->assertSame(
            ['sent' => true, 'queued' => true],
            $service->deliverNotificationEmailNow(
                $userId,
                'quiz_retake_granted',
                $dedupeKey,
            )
        );
        Mail::assertSent(
            MathVerseEventMail::class,
            fn (MathVerseEventMail $mail): bool => $mail->hasTo('student@example.test')
        );
    }

    public function test_quiz_receipt_capability_must_match_the_exact_private_outbox_row(): void
    {
        $deliveryId = '660e8400-e29b-41d4-a716-446655440000';
        $dispatchToken = '770e8400-e29b-41d4-a716-446655440000';
        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->withArgs(fn (string $table, string $columns, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($filters['id'] ?? null) === $deliveryId
                && ($filters['dispatch_token'] ?? null) === $dispatchToken
                && ($filters['channel'] ?? null) === 'email'
                && ($filters['event_type'] ?? null) === 'quiz_result_recorded'
            )
            ->andReturn([
                'data' => [[
                    'id' => $deliveryId,
                    'status' => 'sent',
                    'attempts' => 1,
                ]],
                'error' => null,
                'status' => 200,
            ]);

        $service = new NotificationDeliveryService(
            $supabase,
            Mockery::mock(AdminPushService::class),
        );

        $this->assertSame(
            ['sent' => true, 'queued' => true],
            $service->deliverQuizReceiptByCapabilityNow($deliveryId, $dispatchToken)
        );
    }

    public function test_legacy_quiz_assignment_and_availability_email_rows_are_delivered_as_web_push(): void
    {
        Mail::fake();
        $assignmentDelivery = [
            'id' => '880e8400-e29b-41d4-a716-446655440000',
            'notification_id' => '990e8400-e29b-41d4-a716-446655440000',
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'channel' => 'email',
            'event_type' => 'quiz_assigned',
            'recipient_email' => 'student@example.test',
            'title' => 'New quiz assigned',
            'message' => 'A new quiz is waiting for you.',
            'action_url' => '/student/classes/770e8400-e29b-41d4-a716-446655440000',
            'status' => 'sending',
            'attempts' => 1,
        ];
        $availabilityDelivery = [
            ...$assignmentDelivery,
            'id' => 'aa0e8400-e29b-41d4-a716-446655440000',
            'notification_id' => 'bb0e8400-e29b-41d4-a716-446655440000',
            'event_type' => 'quiz_started',
            'title' => 'Quiz available',
            'message' => 'Your quiz is available now.',
        ];

        $supabase = Mockery::mock(SupabaseService::class);
        $supabase->shouldReceive('adminRpc')->twice()->andReturn([]);
        $supabase->shouldReceive('adminRpcResult')
            ->once()
            ->withArgs(fn (string $function, array $arguments): bool =>
                $function === 'claim_notification_deliveries'
                && ($arguments['p_limit'] ?? null) === 50
            )
            ->andReturn(['data' => [$assignmentDelivery, $availabilityDelivery], 'error' => null, 'status' => 200]);
        $supabase->shouldReceive('adminUpdate')
            ->twice()
            ->withArgs(fn (string $table, array $data, array $filters): bool =>
                $table === 'notification_deliveries'
                && ($data['status'] ?? null) === 'sent'
                && in_array(
                    $filters['id'] ?? null,
                    [$assignmentDelivery['id'], $availabilityDelivery['id']],
                    true,
                )
            )
            ->andReturn([['id' => $assignmentDelivery['id']]]);

        $webPush = Mockery::mock(AdminPushService::class);
        $webPush->shouldReceive('sendToUser')
            ->twice()
            ->withArgs(fn (string $userId, string $title, string $message, string $path, string $tag): bool =>
                $userId === $assignmentDelivery['user_id']
                && $path === $assignmentDelivery['action_url']
                && (str_contains($tag, 'quiz-assigned') || str_contains($tag, 'quiz-started'))
            )
            ->andReturn(true);

        $service = new NotificationDeliveryService($supabase, $webPush);

        $this->assertSame(
            ['claimed' => 2, 'sent' => 2, 'failed' => 0, 'error' => null],
            $service->deliverPending()
        );
        Mail::assertNothingSent();
    }

    private function useProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    private function service(): NotificationDeliveryService
    {
        return new NotificationDeliveryService(
            Mockery::mock(SupabaseService::class),
            Mockery::mock(AdminPushService::class),
        );
    }
}
