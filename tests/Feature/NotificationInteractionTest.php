<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Illuminate\Http\Request;
use Tests\TestCase;

class NotificationInteractionTest extends TestCase
{
    private const USER_ID = '11111111-1111-4111-8111-111111111111';

    private const NOTIFICATION_ID = '22222222-2222-4222-8222-222222222222';

    public function test_notification_snapshot_returns_replaceable_markup(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);

        $response = $this->withSession(['supabase_user' => $this->student()])
            ->getJson('/notifications/snapshot');

        $response->assertOk()->assertJsonStructure(['html']);
        $this->assertStringContainsString(
            'data-notification-root',
            (string) $response->json('html')
        );
    }

    public function test_notification_snapshot_does_not_replace_good_markup_after_a_refresh_failure(): void
    {
        $request = Request::create('/notifications/snapshot');
        $request->attributes->set('dashboard_chrome_fresh', false);

        $response = $this->app->make(NotificationController::class)->snapshot($request);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(
            'Notifications are temporarily unavailable.',
            $response->getData(true)['message']
        );
    }

    public function test_notification_read_returns_a_safe_action_url_to_ajax_navigation(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('notifications', 'id,action_url,read_at', [
                'id' => self::NOTIFICATION_ID,
                'user_id' => self::USER_ID,
            ])
            ->andReturn([[
                'id' => self::NOTIFICATION_ID,
                'action_url' => '/student/dashboard?section=class',
                'read_at' => null,
            ]]);
        $supabase->shouldReceive('adminUpdate')->once()->andReturn([]);

        $response = $this->withSession(['supabase_user' => $this->student()])
            ->postJson('/notifications/' . self::NOTIFICATION_ID . '/read', [
                'follow' => true,
            ]);

        $response->assertOk()->assertJson([
            'message' => 'Notification marked as read.',
            'action_url' => '/student/dashboard?section=class',
        ]);
    }

    public function test_mark_all_read_returns_json_for_the_live_notification_menu(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminUpdate')
            ->once()
            ->with('notifications', \Mockery::type('array'), [
                'user_id' => self::USER_ID,
                'read_at' => ['operator' => 'is', 'value' => 'null'],
            ])
            ->andReturn([]);

        $response = $this->withSession(['supabase_user' => $this->student()])
            ->postJson('/notifications/read-all');

        $response->assertOk()->assertJson([
            'message' => 'All notifications marked as read.',
        ]);
    }

    /** @return array{id: string, role: string, email: string} */
    private function student(): array
    {
        return [
            'id' => self::USER_ID,
            'role' => 'student',
            'email' => 'student@example.test',
        ];
    }
}
