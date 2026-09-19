<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemHealthPageTest extends TestCase
{
    public function test_unused_laravel_queue_does_not_affect_health_or_render_a_card(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware(SupabaseAuth::class);
        config(['queue.default' => 'database', 'database.default' => 'sqlite',
            'database.connections.sqlite.database' => database_path('intentionally-absent.sqlite'),
            'mathverse.deployment_commit' => 'health-test-commit']);
        Schema::shouldReceive('connection')->never();
        DB::shouldReceive('connection')->never();
        Queue::shouldReceive('connection')->never();

        $migrations = array_map(fn (string $path): array => ['migration_key' => basename($path)],
            array_values(array_filter(glob(database_path('supabase/*.sql')) ?: [],
                fn (string $path): bool => !str_ends_with($path, '_rollback.sql'))));
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')->andReturnUsing(
            fn (string $table): array => ['error' => null, 'data' => match ($table) {
                'mathverse_schema_migrations' => $migrations,
                'system_heartbeats' => [['component' => 'laravel_scheduler', 'checked_at' => now()->utc()->toIso8601String()]],
                default => [],
            }]
        );
        $supabase->shouldReceive('adminCountResult')->andReturn(['error' => null, 'count' => 0]);
        $supabase->shouldNotReceive('adminDelete');

        $health = (new SystemHealthService($supabase))->snapshot();
        $this->assertSame('healthy', $health['overall']);
        $this->assertSame(0, $health['issue_count']);
        $this->assertArrayNotHasKey('queue', $health);

        $this->withSession(['supabase_user' => ['id' => '11111111-1111-4111-8111-111111111111', 'role' => 'admin',
            'first_name' => 'Test', 'last_name' => 'Admin', 'avatar_url' => null, 'email' => 'admin@example.test']])
            ->get('/admin/system-health')
            ->assertOk()
            ->assertDontSee('Laravel Queue')
            ->assertDontSee('Run checks again')
            ->assertDontSee('Queue connection / driver')
            ->assertSee('Scheduler last seen')
            ->assertSee('Email and Browser Alerts');
    }
}
