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
            'students' => [['name' => 'Mira', 'classes' => 'Orion', 'last_practiced_at' => '2026-09-12T17:30:00Z']],
            'weak_topics' => [['competency_key' => 'g4-equivalent-fractions', 'mastery' => 40]],
            'daily_activity' => [['activity_date' => '2026-09-13', 'answers' => 8]],
            'recent_activity' => [['student_name' => 'Mira', 'competency_key' => 'g4-equivalent-fractions', 'answered_at' => '2026-09-12T17:30:00Z']],
        ]]]);

        $this->withSession(['supabase_user' => $this->teacher])->get('/teacher/learning-hub?days=7')
            ->assertOk()->assertSee('data-configured="true"', false)
            ->assertSeeText('Weak Topics')->assertSeeText('Daily Activity')
            ->assertSeeText('Student Mastery and Activity')->assertSeeText('Orion')
            ->assertSeeText('Dissimilar and Equivalent Fractions')->assertSeeText('Sep 13')
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
