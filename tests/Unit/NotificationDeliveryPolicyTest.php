<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NotificationDeliveryPolicyTest extends TestCase
{
    private string $migration;
    private string $fiveMinuteMigration;
    private string $quizAssignmentWebPushMigration;
    private string $immediateDeliveryMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2)
            . '/database/supabase/2026_08_31_notification_delivery_policy_followup.sql';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'The notification policy migration must be readable.');
        $this->migration = $contents;

        $fiveMinutePath = dirname(__DIR__, 2)
            . '/database/supabase/2026_08_31_quiz_starting_soon_5_minutes.sql';
        $fiveMinuteContents = file_get_contents($fiveMinutePath);
        $this->assertNotFalse($fiveMinuteContents, 'The five-minute upgrade migration must be readable.');
        $this->fiveMinuteMigration = $fiveMinuteContents;

        $quizAssignmentPath = dirname(__DIR__, 2)
            . '/database/supabase/2026_09_07_quiz_assignment_web_push.sql';
        $quizAssignmentContents = file_get_contents($quizAssignmentPath);
        $this->assertNotFalse($quizAssignmentContents, 'The quiz-assignment Web Push migration must be readable.');
        $this->quizAssignmentWebPushMigration = $quizAssignmentContents;

        $immediateDeliveryPath = dirname(__DIR__, 2)
            . '/database/supabase/2026_09_09_immediate_event_delivery.sql';
        $immediateDeliveryContents = file_get_contents($immediateDeliveryPath);
        $this->assertNotFalse($immediateDeliveryContents, 'The immediate-delivery migration must be readable.');
        $this->immediateDeliveryMigration = $immediateDeliveryContents;
    }

    public function test_completed_auth_security_events_are_not_queued_for_web_push(): void
    {
        $this->assertMatchesRegularExpression(
            "/if new\\.type in \\([\\s\\S]*'password_changed'[\\s\\S]*'email_changed'[\\s\\S]*\\) then\\s+return new;/",
            $this->migration
        );
    }

    public function test_every_database_accepted_quiz_result_is_an_email_event(): void
    {
        $this->assertMatchesRegularExpression(
            "/when new\\.type in \\([\\s\\S]*'quiz_result_recorded'[\\s\\S]*\\) then 'email'/",
            $this->migration
        );
    }

    public function test_quiz_assignments_are_routed_to_web_push_only(): void
    {
        $emailPolicy = strstr(
            $this->quizAssignmentWebPushMigration,
            "delivery_channel := case",
            false
        );
        $this->assertIsString($emailPolicy);
        $emailPolicy = strstr($emailPolicy, "else 'web_push'", true);
        $this->assertIsString($emailPolicy);

        $this->assertStringNotContainsString("'quiz_assigned'", $emailPolicy);
        $this->assertStringContainsString("event_type = 'quiz_assigned'", $this->quizAssignmentWebPushMigration);
        $this->assertStringContainsString("set channel = 'web_push'", $this->quizAssignmentWebPushMigration);
    }

    public function test_quiz_availability_is_routed_to_web_push_only(): void
    {
        $emailPolicy = strstr(
            $this->immediateDeliveryMigration,
            'delivery_channel := case',
            false
        );
        $this->assertIsString($emailPolicy);
        $emailPolicy = strstr($emailPolicy, "else 'web_push'", true);
        $this->assertIsString($emailPolicy);

        $this->assertStringNotContainsString("'quiz_started'", $emailPolicy);
        $this->assertStringContainsString("event_type = 'quiz_started'", $this->immediateDeliveryMigration);
        $this->assertStringContainsString("set channel = 'web_push'", $this->immediateDeliveryMigration);
    }

    public function test_quiz_receipts_request_an_immediate_row_scoped_callback(): void
    {
        $this->assertStringContainsString('dispatch_token uuid', $this->immediateDeliveryMigration);
        $this->assertStringContainsString(
            'revoke all on table public.notification_deliveries',
            $this->immediateDeliveryMigration
        );
        $this->assertStringContainsString(
            "new.event_type <> 'quiz_result_recorded' or new.channel <> 'email'",
            $this->immediateDeliveryMigration
        );
        $this->assertStringContainsString(
            'https://mathmetaverse.space/api/notification-deliveries/quiz-receipt',
            $this->immediateDeliveryMigration
        );
        $this->assertStringContainsString("exception when others then", $this->immediateDeliveryMigration);
        $this->assertStringContainsString(
            'revoke all on function public.request_immediate_quiz_receipt_delivery()',
            $this->immediateDeliveryMigration
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->immediateDeliveryMigration, 'set search_path = pg_catalog, public')
        );
        $this->assertStringContainsString("notify pgrst, 'reload schema'", $this->immediateDeliveryMigration);
    }

    public function test_due_soon_window_is_thirty_minutes(): void
    {
        $this->assertStringContainsString("Quiz due within 30 minutes", $this->migration);
        $this->assertMatchesRegularExpression(
            "/due_at between[\\s\\S]{0,140}interval '30 minutes'/",
            $this->migration
        );
    }

    public function test_starting_soon_window_is_five_minutes(): void
    {
        $this->assertStringContainsString("Quiz starts within 5 minutes", $this->migration);
        $this->assertStringNotContainsString("Quiz starts within 24 hours", $this->migration);
        $this->assertMatchesRegularExpression(
            "/available_at between[\\s\\S]{0,140}interval '5 minutes'/",
            $this->migration
        );

        $this->assertStringContainsString("notifications.type = 'quiz_starts_soon'", $this->fiveMinuteMigration);
        $this->assertStringContainsString("Quiz starts within 5 minutes", $this->fiveMinuteMigration);
        $this->assertStringNotContainsString("Quiz starts within 24 hours", $this->fiveMinuteMigration);
    }
}
