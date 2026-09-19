<?php

namespace Tests\Unit;

use App\Services\SupabaseService;
use App\Services\TeacherLearningHubAnalyticsService;
use Mockery;
use Tests\TestCase;

class TeacherLearningHubAnalyticsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_maps_curriculum_keys_without_exposing_raw_only_labels(): void
    {
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn([
            'error' => null,
            'data' => [[
                'summary' => ['students' => 3],
                'weak_topics' => [['competency_key' => 'g4-equivalent-fractions']],
                'recent_activity' => [],
                'available_classes' => [], 'classes' => [], 'students' => [], 'daily_activity' => [],
            ]],
        ]);

        $state = (new TeacherLearningHubAnalyticsService($database))->dashboard(['id' => 'teacher'], null, 30);
        $this->assertTrue($state['configured']);
        $this->assertSame(3, $state['summary']['students']);
        $this->assertSame('Dissimilar and Equivalent Fractions', $state['weak_topics'][0]['topic_title']);
        $this->assertSame('UTC', $state['timezone']);
    }

    public function test_transport_failure_returns_a_renderable_unavailable_state(): void
    {
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andThrow(new \RuntimeException('Transport unavailable'));

        $state = (new TeacherLearningHubAnalyticsService($database))->dashboard(['id' => 'teacher']);

        $this->assertFalse($state['configured']);
        $this->assertStringContainsString('temporarily unavailable', $state['message']);
        $this->assertSame([], $state['daily_activity']);
        $this->assertSame(0, $state['summary']['answers']);
    }

    public function test_malformed_collection_rows_are_filtered_and_partial_rows_have_defaults(): void
    {
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn([
            'error' => null, 'data' => [[
                'timezone' => 'Asia/Manila',
                'students' => [null, 'invalid', []],
                'recent_activity' => [false, ['competency_key' => 'g4-equivalent-fractions']],
                'available_classes' => [[]],
            ]],
        ]);

        $state = (new TeacherLearningHubAnalyticsService($database))->dashboard(['id' => 'teacher']);

        $this->assertTrue($state['configured']);
        $this->assertSame('Asia/Manila', $state['timezone']);
        $this->assertCount(1, $state['students']);
        $this->assertSame('Student', $state['students'][0]['name']);
        $this->assertSame('Class', $state['available_classes'][0]['name']);
        $this->assertSame('Student', $state['recent_activity'][0]['student_name']);
    }

    public function test_a_missing_rpc_returns_an_unconfigured_state(): void
    {
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn([
            'error' => 'Function unavailable', 'status' => 404, 'data' => [],
        ]);
        $state = (new TeacherLearningHubAnalyticsService($database))->dashboard(['id' => 'teacher']);
        $this->assertFalse($state['configured']);
        $this->assertStringContainsString('SQL migration', $state['message']);
    }
}
