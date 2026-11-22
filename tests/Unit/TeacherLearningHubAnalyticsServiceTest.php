<?php

namespace Tests\Unit;

use App\Services\SupabaseService;
use App\Services\TeacherLearningHubAnalyticsService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class TeacherLearningHubAnalyticsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
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
        $this->assertStringContainsString('temporarily unavailable', $state['message']);
        $this->assertStringNotContainsString('migration', strtolower($state['message']));
    }

    public function test_class_daily_chart_fills_the_recorded_period_through_today_without_changing_totals(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        CarbonImmutable::setTestNow('2026-11-22T10:00:00+08:00');
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn(['error' => null, 'data' => [[
            'timezone' => 'Asia/Manila',
            'period_start' => '2026-09-12T16:00:00Z',
            'period_end' => '2026-11-22T02:00:00Z',
            'summary' => ['answers' => 12, 'hints_used' => 3],
            'daily_activity' => [
                ['activity_date' => '2026-09-13', 'answers' => 8, 'correct' => 5, 'hints_used' => 2],
                ['activity_date' => '2026-11-03', 'answers' => 4, 'correct' => 3, 'hints_used' => 1],
                ['activity_date' => '2026-11-23', 'answers' => 99, 'correct' => 99, 'hints_used' => 0],
            ],
        ]]]);

        $state = (new TeacherLearningHubAnalyticsService($database))->dashboard(['id' => 'teacher']);
        $days = $state['daily_activity'];
        $byDate = array_column($days, null, 'activity_date');
        $this->assertCount(71, $days);
        $this->assertSame('2026-09-13', $days[0]['activity_date']);
        $this->assertSame('2026-11-22', end($days)['activity_date']);
        $this->assertSame(8, $byDate['2026-09-13']['answers']);
        $this->assertSame(5, $byDate['2026-09-13']['correct']);
        $this->assertSame(62.5, $byDate['2026-09-13']['accuracy']);
        $this->assertSame(2, $byDate['2026-09-13']['hints_used']);
        $this->assertSame(0, $byDate['2026-11-22']['answers']);
        $this->assertArrayNotHasKey('2026-11-23', $byDate);
        $this->assertSame(12, array_sum(array_column($days, 'answers')));
        $this->assertSame(8, array_sum(array_column($days, 'correct')));
        $this->assertSame(3, array_sum(array_column($days, 'hints_used')));
        $this->assertSame(12, $state['summary']['answers']);
    }

    public function test_student_daily_chart_preserves_active_days_and_accuracy_while_padding_inactive_days(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        CarbonImmutable::setTestNow('2026-11-22T10:00:00+08:00');
        $activity = [];
        for ($index = 0; $index < 15; $index++) {
            $activity[] = [
                'activity_date' => CarbonImmutable::parse('2026-09-13')->addDays($index)->toDateString(),
                'answers' => 5, 'correct' => 3, 'accuracy' => 60.0, 'hints_used' => 2,
            ];
        }
        $activity[] = ['activity_date' => '2026-11-03', 'answers' => 10, 'correct' => 9, 'accuracy' => 90.0, 'hints_used' => 3];
        $activity[] = ['activity_date' => '2026-11-23', 'answers' => 99, 'correct' => 99, 'accuracy' => 100.0, 'hints_used' => 0];
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn(['error' => null, 'data' => [[
            'timezone' => 'Asia/Manila',
            'period_start' => '2026-09-13T00:00:00+08:00',
            'period_end' => '2026-11-25T02:00:00Z',
            'summary' => ['answers' => 85, 'correct' => 54, 'hints_used' => 33, 'active_days' => 16],
            'daily_activity' => $activity,
        ]]]);

        $state = (new TeacherLearningHubAnalyticsService($database))->studentDashboard(['id' => 'teacher'], 'student');
        $days = $state['daily_activity'];
        $byDate = array_column($days, null, 'activity_date');
        $this->assertCount(71, $days);
        $this->assertSame('2026-11-22', end($days)['activity_date']);
        $this->assertSame(16, $state['summary']['active_days']);
        $this->assertCount(16, array_filter($days, fn (array $day): bool => $day['answers'] > 0));
        $this->assertSame(90.0, $byDate['2026-11-03']['accuracy']);
        $this->assertSame(9, $byDate['2026-11-03']['correct']);
        $this->assertSame(3, $byDate['2026-11-03']['hints_used']);
        foreach (['answers', 'correct', 'hints_used', 'accuracy'] as $metric) {
            $this->assertSame(0, $byDate['2026-11-22'][$metric]);
        }
        $this->assertSame(85, array_sum(array_column($days, 'answers')));
        $this->assertSame(54, array_sum(array_column($days, 'correct')));
        $this->assertSame(33, array_sum(array_column($days, 'hints_used')));
    }

    public function test_bounded_empty_activity_has_a_zero_day_and_legacy_payloads_stay_compatible(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        CarbonImmutable::setTestNow('2026-11-22T10:00:00+08:00');
        $database = Mockery::mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn(['error' => null, 'data' => [[
            'period_start' => '2026-11-21T16:00:00Z', 'period_end' => '2026-11-22T02:00:00Z', 'daily_activity' => [],
        ]]]);
        $database->shouldReceive('adminRpcResult')->once()->andReturn(['error' => null, 'data' => [['daily_activity' => []]]]);
        $service = new TeacherLearningHubAnalyticsService($database);

        $bounded = $service->dashboard(['id' => 'teacher']);
        $this->assertCount(1, $bounded['daily_activity']);
        $this->assertSame('2026-11-22', $bounded['daily_activity'][0]['activity_date']);
        $this->assertSame(0, $bounded['daily_activity'][0]['answers']);
        $this->assertSame([], $service->dashboard(['id' => 'teacher'])['daily_activity']);
    }
}
