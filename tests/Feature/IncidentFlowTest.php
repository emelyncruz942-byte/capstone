<?php

namespace Tests\Feature;

use App\Services\IncidentAlertService;
use App\Services\IncidentReporter;
use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IncidentFlowTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const LEASE = '22222222-2222-4222-8222-222222222222';
    private const ADMIN = '33333333-3333-4333-8333-333333333333';

    public function test_unhandled_errors_have_a_matching_safe_reference_in_response_and_diagnostics(): void
    {
        config(['mathverse.incidents.enabled' => true]);
        Route::middleware('web')->get('/test-incident-error', fn () => throw new \RuntimeException('Internal provider details'));
        $service = $this->mock(SupabaseService::class);
        $captured = null;
        $service->shouldReceive('adminInsertResult')->once()->withArgs(function ($table, $payload) use (&$captured): bool {
            $captured = $payload;
            return $table === 'incident_events' && $payload['kind'] === 'error';
        })->andReturn(['error' => null, 'data' => []]);
        $response = $this->getJson('/test-incident-error?token_hash=synthetic-secret');
        $response->assertStatus(500)->assertDontSee('Internal provider details')->assertDontSee('synthetic-secret');
        $reference = $response->headers->get('X-MathVerse-Reference');
        $this->assertMatchesRegularExpression('/^MV-[A-F0-9]{16}$/', $reference);
        $response->assertJsonPath('reference_id', $reference);
        $this->assertSame($reference, $captured['reference_id']);
        $this->assertSame('test-incident-error', $captured['route_name']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($captured));
    }

    public function test_login_failure_hashes_identity_and_network_and_flashes_a_reference(): void
    {
        config(['mathverse.incidents.enabled' => true]);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('signIn')->once()->andReturn(['error' => 'Invalid login credentials']);
        $service->shouldReceive('adminInsertResult')->once()->withArgs(fn ($table, $data): bool => $table === 'incident_events'
            && $data['kind'] === 'auth_failure' && strlen($data['subject_hash']) === 64 && strlen($data['network_hash']) === 64
            && !str_contains(json_encode($data), 'student@example.test') && !str_contains(json_encode($data), 'Password1!'))
            ->andReturn(['error' => null, 'data' => []]);
        $this->from('/')->post('/login', ['email' => 'student@example.test', 'password' => 'Password1!'])
            ->assertRedirect('/')->assertSessionHas('error', fn ($message): bool => str_contains($message, 'Reference: MV-'));
    }

    public function test_diagnostic_cache_failure_cannot_replace_the_original_error_with_another_failure(): void
    {
        config(['mathverse.incidents.enabled' => true]);
        RateLimiter::shouldReceive('tooManyAttempts')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        $this->mock(SupabaseService::class)->shouldNotReceive('adminInsertResult');
        $request = \Illuminate\Http\Request::create('/test-incident-cache', 'GET');
        $reference = app(IncidentReporter::class)->capture($request, 'error', 500);
        $this->assertMatchesRegularExpression('/^MV-[A-F0-9]{16}$/', $reference);
    }

    public function test_monitor_refuses_missing_wrong_and_query_string_secrets_before_any_checks(): void
    {
        config(['mathverse.incidents.monitor_token' => str_repeat('x', 40)]);
        $alerts = $this->mock(IncidentAlertService::class);
        $alerts->shouldNotReceive('check');
        $this->postJson('/api/operations/monitor')->assertUnauthorized();
        $this->postJson('/api/operations/monitor?token='.str_repeat('x', 40))->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer wrong')->postJson('/api/operations/monitor')->assertUnauthorized();
    }

    public function test_handled_json_denials_expose_a_reference_and_do_not_leak_request_details(): void
    {
        config(['mathverse.incidents.enabled' => true]);
        Route::middleware('web')->get('/test-incident-denied', fn () => abort(403));
        $this->mock(SupabaseService::class)->shouldReceive('adminInsertResult')->once()
            ->withArgs(fn ($table,$data): bool => $table === 'incident_events' && $data['kind'] === 'access_denied')
            ->andReturn(['error' => null, 'data' => []]);
        $response = $this->getJson('/test-incident-denied');
        $response->assertForbidden()->assertJsonPath('reference_id', $response->headers->get('X-MathVerse-Reference'))
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_server_rendered_error_page_keeps_security_headers_and_nonce_and_shows_reference(): void
    {
        config(['mathverse.incidents.enabled' => true]);
        $this->withoutVite();
        Route::middleware('web')->get('/test-incident-html', fn () => throw new \RuntimeException('Internal error details'));
        $this->mock(SupabaseService::class)->shouldReceive('adminInsertResult')->once()->andReturn(['error' => null, 'data' => []]);
        $response = $this->get('/test-incident-html');
        $response->assertStatus(500)->assertSee($response->headers->get('X-MathVerse-Reference'))
            ->assertDontSee('Internal error details')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('nonce-', $response->headers->get('Content-Security-Policy'));
    }

    public function test_external_monitor_runs_without_the_scheduler_or_a_queue_worker(): void
    {
        config(['mathverse.incidents.monitor_token' => str_repeat('x', 40)]);
        $this->mock(IncidentAlertService::class)->shouldReceive('check')->once()
            ->andReturn(['enabled' => true, 'checked' => 9, 'active' => 1, 'failed' => 0, 'notified' => 1]);
        $this->mock(SupabaseService::class)->shouldReceive('adminUpsert')->once()->andReturn([['component' => 'independent_incident_monitor']]);
        $this->withHeader('Authorization', 'Bearer '.str_repeat('x', 40))->postJson('/api/operations/monitor')
            ->assertOk()->assertJsonPath('notified', 1)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_stale_scheduler_is_immediately_dispatched_through_durable_email_and_webhook(): void
    {
        $service = $this->alertFixture();
        $this->mock(NotificationDeliveryService::class)->shouldReceive('deliverStandaloneEmailNow')->once()
            ->withArgs(fn (...$args): bool => $args[0] === 'incident_alert' && $args[1] === 'admin@example.test'
                && str_contains($args[7], self::ID) && $args[8] === self::ADMIN)
            ->andReturn(['sent' => true, 'queued' => true]);
        Http::fake(['alerts.example.test/*' => Http::response([], 200)]);
        $service->shouldReceive('adminRpcResult')->once()->withArgs(fn ($name,$args): bool => $name === 'finish_incident_notification'
            && $args['p_complete'] && ((array) $args['p_channels'])['webhook'] && ((array) $args['p_channels'])['email:'.self::ADMIN])
            ->andReturn(['error' => null, 'data' => [['completed' => true]]]);
        $stats = app(IncidentAlertService::class)->check();
        $this->assertSame(1, $stats['notified']); $this->assertSame(0, $stats['failed']);
        Http::assertSentCount(1);
    }

    public function test_partial_channel_failure_preserves_successful_channels_and_does_not_resend_email(): void
    {
        $service = $this->alertFixture(['bell' => true, 'email:'.self::ADMIN => true]);
        $this->mock(NotificationDeliveryService::class)->shouldNotReceive('deliverStandaloneEmailNow');
        Http::fake(['alerts.example.test/*' => Http::response([], 503)]);
        $service->shouldReceive('adminRpcResult')->once()->withArgs(fn ($name,$args): bool => $name === 'finish_incident_notification'
            && !$args['p_complete'] && ((array) $args['p_channels'])['email:'.self::ADMIN]
            && !str_contains($args['p_error'], 'webhook-secret'))
            ->andReturn(['error' => null, 'data' => [['completed' => true]]]);
        $stats = app(IncidentAlertService::class)->check();
        $this->assertSame(1, $stats['failed']);
    }

    private function alertFixture(array $channels = [])
    {
        config(['mathverse.incidents.enabled' => true, 'mathverse.incidents.webhook_url' => 'https://alerts.example.test/webhook-secret']);
        $this->mock(SystemHealthService::class)->shouldReceive('snapshot')->once()->andReturn([
            'scheduler' => ['status' => 'critical', 'message' => 'Scheduler heartbeat stale.', 'age_seconds' => 900],
            'audit' => ['status' => 'healthy', 'stale' => 0], 'database' => ['status' => 'healthy', 'message' => 'Normal.', 'latency_ms' => 10],
        ]);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->with('incident_signal_counts', ['p_window_seconds' => 600])
            ->andReturn(['error' => null, 'data' => [['errors' => 0, 'repeated_failures' => 0, 'admin_actions' => 0]]]);
        $service->shouldReceive('adminRpcResult')->withArgs(fn ($name,$args): bool => $name === 'sync_incident_signal')
            ->andReturnUsing(fn ($name,$args): array => ['error' => null, 'data' => [$args['p_key'] === 'scheduler'
                ? ['id' => self::ID, 'lease' => self::LEASE, 'signal_key' => 'scheduler', 'severity' => 'critical',
                    'summary' => 'Scheduler heartbeat stale.', 'notification_generation' => 1, 'delivered_channels' => $channels, 'notify' => true]
                : ['notify' => false]]]);
        if (!($channels['bell'] ?? false)) {
            $service->shouldReceive('adminRpcResult')->once()->with('notify_incident_admins', ['p_id' => self::ID, 'p_generation' => 1])
                ->andReturn(['error' => null, 'data' => [['saved' => true]]]);
        }
        $service->shouldReceive('adminSelectResult')->once()->andReturn(['error' => null, 'data' => [[
            'id' => self::ADMIN, 'email' => 'admin@example.test', 'first_name' => 'A', 'last_name' => 'Admin',
        ]]]);
        return $service;
    }
}
