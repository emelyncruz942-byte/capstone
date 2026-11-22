<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'Asia/Manila',
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => str_repeat('a', 40),
            'services.supabase.service_key' => str_repeat('b', 40),
        ]);
        CarbonImmutable::setTestNow('2026-11-22T10:00:00+08:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_complete_history_reads_beyond_the_provider_thousand_row_default(): void
    {
        $records = array_map(fn (int $id): array => ['id' => $id], range(1, 2017));
        Http::fake(['project.supabase.co/*' => function ($request) use ($records) {
            $offset = (int) $request['offset'];
            $page = array_slice($records, $offset, 1000);
            return Http::response($page, 206, ['Content-Range' => $offset . '-' . ($offset + count($page) - 1) . '/2017']);
        }]);

        $rows = app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id', ['student_id' => 'owner']);

        $this->assertSame($records, $rows);
        Http::assertSentCount(3);
        $this->assertSame([0, 1000, 2000], array_map(fn ($pair): int => (int) $pair[0]['offset'], Http::recorded()->all()));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('eq.owner', $request['student_id']);
            $this->assertSame('lte.2026-11-22T02:00:00+00:00', $request['created_at']);
            $this->assertSame('id.asc', $request['order']);
        }
    }

    public function test_smaller_project_caps_advance_by_returned_rows_without_losing_history(): void
    {
        $records = array_map(fn (int $id): array => ['id' => $id], range(1, 63));
        Http::fake(['project.supabase.co/*' => function ($request) use ($records) {
            $offset = (int) $request['offset'];
            $page = array_slice($records, $offset, 25);
            return Http::response($page, 206, ['Content-Range' => $offset . '-' . ($offset + count($page) - 1) . '/63']);
        }]);

        $rows = app(SupabaseService::class)->adminSelectHistory('classes', 'id', ['teacher_id' => 'owner']);

        $this->assertSame($records, $rows);
        $this->assertSame([0, 25, 50], array_map(fn ($pair): int => (int) $pair[0]['offset'], Http::recorded()->all()));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('eq.owner', $request['teacher_id']);
            $this->assertSame('is.null', $request['deleted_at']);
        }
    }

    public function test_long_uuid_scopes_remain_scoped_and_keep_the_requested_global_order(): void
    {
        $ids = array_map(fn (int $id): string => sprintf('%08d-0000-4000-8000-%012d', $id, $id), range(1, 100));
        $records = [];
        foreach ($ids as $index => $id) {
            $records[] = [
                'id' => $id,
                'session_id' => $id,
                'created_at' => CarbonImmutable::parse('2026-09-01T00:00:00Z')->addSeconds(100 - $index)->toIso8601String(),
                'correct_answers' => $index % 6,
            ];
        }
        Http::fake(['project.supabase.co/*' => function ($request) use ($records) {
            $scope = explode(',', trim(substr($request['session_id'], 3), '()'));
            $page = array_values(array_filter($records, fn (array $row): bool => in_array($row['session_id'], $scope, true)));
            usort($page, fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));
            $page = array_map(fn (array $row): array => array_intersect_key($row, array_flip(explode(',', $request['select']))), $page);
            return Http::response($page, 200, ['Content-Range' => '0-' . (count($page) - 1) . '/' . count($page)]);
        }]);

        $rows = app(SupabaseService::class)->adminSelectHistory('quiz_results', 'correct_answers,created_at', [
            'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $ids) . ')'],
            'student_id' => 'owner', 'is_counted' => true, 'order' => 'created_at.asc',
        ]);

        $this->assertCount(100, $rows);
        $this->assertSame(array_reverse(array_column($records, 'created_at')), array_column($rows, 'created_at'));
        $this->assertArrayNotHasKey('id', $rows[0]);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertLessThanOrEqual(2003, strlen($request['session_id']));
            $this->assertSame('eq.owner', $request['student_id']);
            $this->assertSame('eq.true', $request['is_counted']);
            $this->assertSame('created_at.asc,id.asc', $request['order']);
        }
    }

    public static function malformedRanges(): array
    {
        return [[''], ['0-0/*'], ['1-1/2'], ['0-2/3'], ['0-0/0'], ['not-a-range']];
    }

    #[DataProvider('malformedRanges')]
    public function test_nonempty_pages_without_valid_ranges_fail_fast(string $range): void
    {
        Http::fake(['project.supabase.co/*' => Http::response([['id' => 1]], 200, ['Content-Range' => $range])]);
        $this->expectException(\RuntimeException::class);
        try {
            app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_failed_middle_page_never_returns_a_partial_report(): void
    {
        Http::fake(['project.supabase.co/*' => fn ($request) => (int) $request['offset'] === 0
            ? Http::response([['id' => 1]], 206, ['Content-Range' => '0-0/2'])
            : Http::response(['message' => 'Unavailable'], 503)]);
        $this->expectException(\RuntimeException::class);
        app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id');
    }

    public function test_a_changing_total_or_missing_tail_never_becomes_a_partial_success(): void
    {
        Http::fakeSequence()
            ->push([['id' => 1]], 206, ['Content-Range' => '0-0/2'])
            ->push([['id' => 2]], 206, ['Content-Range' => '1-1/3']);
        $this->expectException(\RuntimeException::class);
        app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id');
    }

    public function test_an_empty_middle_page_cannot_hide_the_remaining_records(): void
    {
        Http::fakeSequence()
            ->push([['id' => 1]], 206, ['Content-Range' => '0-0/2'])
            ->push([], 200, ['Content-Range' => '*/2']);
        $this->expectException(\RuntimeException::class);
        app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id');
    }

    public function test_existing_date_filters_are_combined_with_the_current_instant(): void
    {
        Http::fake(['project.supabase.co/*' => Http::response([], 200, ['Content-Range' => '*/0'])]);
        app(SupabaseService::class)->adminSelectHistory('quiz_results', 'id', [
            'student_id' => 'owner', 'created_at' => ['operator' => 'gte', 'value' => '2026-09-01T00:00:00Z'],
        ]);
        $request = Http::recorded()[0][0];
        $this->assertSame('gte.2026-09-01T00:00:00Z', $request['created_at']);
        $this->assertSame('(created_at.lte.2026-11-22T02:00:00+00:00)', $request['and']);
        $this->assertSame('eq.owner', $request['student_id']);
    }

    public function test_teacher_quiz_chart_starts_on_october_12_and_includes_archived_class_activity_through_today(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $teacher = ['id' => '11111111-1111-4111-8111-111111111111', 'role' => 'teacher'];
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectHistory')->once()->with('classes', 'id,class_name,archived_at', ['teacher_id' => $teacher['id']])
            ->andReturn([['id' => 'old', 'class_name' => 'Previous Class', 'archived_at' => '2026-11-01'], ['id' => 'new', 'class_name' => 'Current Class', 'archived_at' => null]]);
        $database->shouldReceive('adminSelectHistory')->once()->with('quiz_sessions', 'id,topic,created_at,class_id', [
            'class_id' => ['operator' => 'in', 'value' => '(old,new)'],
        ])->andReturn([['id' => 'early', 'class_id' => 'old', 'created_at' => '2026-10-11T14:44:00Z'], ['id' => 'late', 'class_id' => 'new', 'created_at' => '2026-11-22T00:00:00Z']]);
        $database->shouldReceive('adminSelectHistory')->once()->with('quiz_results', 'correct_answers,total_questions,created_at,session_id,student_id', [
            'session_id' => ['operator' => 'in', 'value' => '(early,late)'], 'is_counted' => true, 'order' => 'created_at.asc',
        ])->andReturn([
            ['session_id' => 'early', 'correct_answers' => 1, 'total_questions' => 5, 'created_at' => '2026-10-11T16:10:00Z'],
            ['session_id' => 'late', 'correct_answers' => 4, 'total_questions' => 5, 'created_at' => '2026-11-22T01:00:00Z'],
        ]);

        $response = $this->withSession(['supabase_user' => $teacher])->get('/teacher/stats')->assertOk();
        $response->assertJsonPath('totalAttempts', 2)->assertJsonPath('classAccuracy.0.attempts', 1);
        $days = $response->json('attemptsPerDay');
        $this->assertSame('2026-10-12', $days[0]['iso_date']);
        $this->assertSame('2026-11-22', end($days)['iso_date']);
        $this->assertSame(2, array_sum(array_column($days, 'count')));
        $this->assertCount(42, $days);
        $this->assertArrayNotHasKey('2026-10-11', array_column($days, 'count', 'iso_date'));
        $this->assertSame(0, array_column($days, 'count', 'iso_date')['2026-10-13']);
    }

    public function test_teacher_attempt_chart_does_not_invent_history_from_an_unanswered_session(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $teacher = ['id' => '11111111-1111-4111-8111-111111111111', 'role' => 'teacher'];
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectHistory')->once()->with('classes', 'id,class_name,archived_at', ['teacher_id' => $teacher['id']])
            ->andReturn([['id' => 'class', 'class_name' => 'Grade 4', 'archived_at' => null]]);
        $database->shouldReceive('adminSelectHistory')->once()->with('quiz_sessions', 'id,topic,created_at,class_id', [
            'class_id' => ['operator' => 'in', 'value' => '(class)'],
        ])->andReturn([['id' => 'waiting', 'class_id' => 'class', 'created_at' => '2026-10-11T14:44:00Z']]);
        $database->shouldReceive('adminSelectHistory')->once()->with('quiz_results', 'correct_answers,total_questions,created_at,session_id,student_id', [
            'session_id' => ['operator' => 'in', 'value' => '(waiting)'], 'is_counted' => true, 'order' => 'created_at.asc',
        ])->andReturn([]);

        $response = $this->withSession(['supabase_user' => $teacher])->get('/teacher/stats')->assertOk();
        $response->assertJsonPath('totalAttempts', 0)->assertJsonPath('totalQuizzes', 1);
        $this->assertSame([
            ['date' => 'Nov 22, 2026', 'iso_date' => '2026-11-22', 'count' => 0],
        ], $response->json('attemptsPerDay'));
    }

    public function test_admin_quiz_chart_starts_on_october_12_independently_of_earlier_registration_and_library_dates(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $admin = ['id' => '11111111-1111-4111-8111-111111111111', 'role' => 'admin'];
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectHistory')->once()->with('quiz_results',
            'correct_answers,total_questions,created_at,session_id,student_id', ['is_counted' => true, 'order' => 'created_at.asc'])
            ->andReturn([
                ['correct_answers' => 1, 'total_questions' => 5, 'created_at' => '2026-10-11T16:10:00Z'],
                ['correct_answers' => 4, 'total_questions' => 5, 'created_at' => '2026-11-22T01:00:00Z'],
            ]);
        $database->shouldReceive('adminSelectHistory')->once()->with('quizzes', 'id,topic,teacher_id,created_at')
            ->andReturn([['id' => 'quiz', 'created_at' => '2026-09-01T00:00:00Z']]);
        $database->shouldReceive('adminSelectHistory')->once()->with('profiles', 'id,role,created_at')
            ->andReturn([
                ['id' => 'early', 'role' => 'teacher', 'created_at' => '2026-08-14T16:30:00Z'],
                ['id' => 'current', 'role' => 'student', 'created_at' => '2026-11-22T01:00:00Z'],
            ]);

        $response = $this->withSession(['supabase_user' => $admin])->get('/admin/stats')->assertOk();
        $response->assertJsonPath('totalAttempts', 2)->assertJsonPath('totalUsers', 2);
        $attempts = $response->json('attemptsPerDay');
        $registrations = $response->json('registrationsPerDay');
        $this->assertSame('2026-10-12', $attempts[0]['iso_date']);
        $this->assertSame('2026-08-15', $registrations[0]['iso_date']);
        $this->assertSame('2026-11-22', end($attempts)['iso_date']);
        $this->assertSame(1, array_column($attempts, 'count', 'iso_date')['2026-10-12']);
        $this->assertSame(0, array_column($attempts, 'count', 'iso_date')['2026-10-13']);
        $this->assertCount(42, $attempts);
        $this->assertSame(2, array_sum(array_column($attempts, 'count')));
        $this->assertSame(2, array_sum(array_column($registrations, 'count')));
    }
}
