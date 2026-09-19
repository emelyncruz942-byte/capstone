<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use Tests\TestCase;

class AdminAuditPaginationTest extends TestCase
{
    public function test_all_streams_is_unfiltered_and_survives_pagination_with_local_date_boundaries(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectPage')->andReturn(['data' => [], 'total' => 0]);
        $database->shouldReceive('adminCount')->andReturn(0);
        $database->shouldReceive('adminSelect')->andReturn([]);
        $database->shouldReceive('adminRpcResult')->once()->with('search_audit_logs', [
            'p_search' => null, 'p_category' => null, 'p_actor_role' => null,
            'p_action' => null, 'p_outcome' => null,
            'p_from' => '2026-09-12T16:00:00+00:00',
            'p_to' => '2026-09-13T16:00:00+00:00',
            'p_limit' => 30, 'p_offset' => 0,
        ])->andReturn(['error' => null, 'data' => [[
            'actor_name' => 'Nova', 'actor_role' => 'admin',
            'created_at' => '2026-09-12T17:30:00Z', 'action' => 'user.suspended',
            'category' => 'security', 'outcome' => 'succeeded', 'total_count' => 60,
        ]]]);
        $delivery = $this->mock(NotificationDeliveryService::class);
        $delivery->shouldReceive('isReady')->once()->andReturn(true);
        $delivery->shouldReceive('emailConfigurationIssue')->once()->andReturn(null);

        $this->withSession(['supabase_user' => [
            'id' => '11111111-1111-4111-8111-111111111111', 'role' => 'admin',
            'first_name' => 'Nova', 'last_name' => 'Admin', 'email' => 'nova@example.com',
            'avatar_url' => null,
        ]])->get('/admin/dashboard?section=audit&audit_category=all&audit_from=2026-09-13&audit_to=2026-09-13')
            ->assertOk()->assertSeeText('Succeeded')
            ->assertSee('audit_category=all', false)->assertSee('audit_page=2', false);
    }
}
