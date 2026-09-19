<?php

namespace Tests\Unit;

use App\Services\SupabaseService;
use App\Services\SystemHealthService;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemHealthHeartbeatTest extends TestCase
{
    public function test_scheduler_heartbeat_is_upserted(): void
    {
        $service = $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminUpsert')->once()
                ->withArgs(fn (string $table, array $data, string $conflict): bool =>
                    $table === 'system_heartbeats'
                    && $conflict === 'component'
                    && $data['component'] === 'laravel_scheduler'
                )
                ->andReturn([['component' => 'laravel_scheduler']]);
        });

        $this->assertTrue((new SystemHealthService($service))->recordSchedulerHeartbeat());
    }
}
