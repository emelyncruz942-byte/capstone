<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Tests\TestCase;

class TeacherLearningHubInsightsTest extends TestCase
{
    private array $teacher = [
        'id' => '11111111-1111-4111-8111-111111111111',
        'role' => 'teacher', 'first_name' => 'Nova', 'last_name' => 'Teacher',
        'email' => 'nova@example.com', 'avatar_url' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(SupabaseAuth::class);
    }

    public function test_populated_insights_render_all_analytics_sections_and_local_dates(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->with('teacher_learning_hub_analytics', [
            'p_teacher_id' => $this->teacher['id'], 'p_class_id' => null, 'p_days' => 7,
        ])->andReturn(['error' => null, 'data' => [[
            'timezone' => 'Asia/Manila',
            'summary' => ['students' => 1, 'answers' => 8],
            'available_classes' => [['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Orion', 'grade_level' => 4]],
            'classes' => [['class_name' => 'Orion']],
            'students' => [[
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Mira',
                'grade_level' => 4,
                'classes' => 'Orion',
                'last_practiced_at' => '2026-09-12T17:30:00Z',
            ]],
            'weak_topics' => [['competency_key' => 'g4-equivalent-fractions', 'mastery' => 40]],
            'daily_activity' => [['activity_date' => '2026-09-13', 'answers' => 8]],
            'recent_activity' => [['student_name' => 'Mira', 'competency_key' => 'g4-equivalent-fractions', 'answered_at' => '2026-09-12T17:30:00Z']],
        ]]]);

        $this->withSession(['supabase_user' => $this->teacher])->get('/teacher/learning-hub?days=7')
            ->assertOk()->assertSee('data-configured="true"', false)
            ->assertSeeText('Weak Topics')->assertSeeText('Daily Activity')
            ->assertSeeText('Student Mastery and Activity')->assertSeeText('Orion')
            ->assertSeeText('Dissimilar and Equivalent Fractions')->assertSeeText('Sep 13')
            ->assertSeeText('Solo stats')
            ->assertSee('/teacher/learning-hub/students/22222222-2222-4222-8222-222222222222?days=7', false)
            ->assertSeeText('Asia/Manila');
    }

    public function test_teacher_can_open_authorized_student_solo_statistics(): void
    {
        $studentId = '22222222-2222-4222-8222-222222222222';
        $classId = '33333333-3333-4333-8333-333333333333';
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->with(
            'teacher_learning_hub_student_analytics',
            [
                'p_teacher_id' => $this->teacher['id'],
                'p_student_id' => $studentId,
                'p_class_id' => $classId,
                'p_days' => 7,
            ]
        )->andReturn(['error' => null, 'data' => [[
            'timezone' => 'Asia/Manila',
            'student' => ['id' => $studentId, 'name' => 'Mira Learner', 'grade_level' => 4],
            'summary' => [
                'answers' => 8,
                'correct' => 6,
                'accuracy' => 75,
                'average_mastery' => 62.5,
                'mastered_topics' => 1,
                'practised_topics' => 3,
                'hints_used' => 2,
                'hint_usage_rate' => 25,
                'improvement' => 6,
                'active_days' => 4,
            ],
            'classes' => [['id' => $classId, 'name' => 'Orion', 'grade_level' => 4]],
            'topics' => [[
                'competency_key' => 'g4-equivalent-fractions',
                'grade_level' => 4,
                'mastery' => 48,
                'period_accuracy' => 75,
                'period_answers' => 4,
                'lifetime_attempts' => 8,
                'period_hints' => 2,
                'improvement' => 6,
                'last_practiced_at' => '2026-09-12T17:30:00Z',
            ]],
            'daily_activity' => [['activity_date' => '2026-09-13', 'answers' => 4, 'accuracy' => 75]],
            'recent_activity' => [[
                'competency_key' => 'g4-equivalent-fractions',
                'difficulty' => 2,
                'is_correct' => true,
                'hints_used' => 1,
                'answered_at' => '2026-09-12T17:30:00Z',
            ]],
        ]]]);

        $this->withSession(['supabase_user' => $this->teacher])
            ->get("/teacher/learning-hub/students/{$studentId}?class_id={$classId}&days=7")
            ->assertOk()
            ->assertSee('data-configured="true"', false)
            ->assertSeeText('Mira Learner')
            ->assertSeeText('Solo Stats')
            ->assertSeeText('Topic Mastery and Activity')
            ->assertSeeText('Dissimilar and Equivalent Fractions')
            ->assertSeeText('Orion')
            ->assertSeeText('Sep 13')
            ->assertSeeText('Asia/Manila');
    }

    public function test_data_service_failure_keeps_the_page_and_its_navigation_usable(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andThrow(new \RuntimeException('Network unavailable'));
        $this->withSession(['supabase_user' => $this->teacher])->get('/teacher/learning-hub')
            ->assertOk()->assertSee('data-configured="false"', false)
            ->assertSeeText('temporarily unavailable')->assertSeeText('All active classes');
    }
}
