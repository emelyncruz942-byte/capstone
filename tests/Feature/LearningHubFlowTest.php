<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\AdaptivePracticeService;
use Tests\TestCase;

class LearningHubFlowTest extends TestCase
{
    private array $student = [
        'id' => '11111111-1111-4111-8111-111111111111',
        'role' => 'student',
        'grade_level' => 6,
        'first_name' => 'Nova',
        'last_name' => 'Learner',
        'email' => 'nova@example.com',
        'avatar_url' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(SupabaseAuth::class);
    }

    public function test_student_can_open_the_autonomous_learning_hub(): void
    {
        $practice = $this->mock(AdaptivePracticeService::class);
        $practice->shouldReceive('dashboard')
            ->once()
            ->with($this->student)
            ->andReturn($this->hubState());

        $response = $this->withSession(['supabase_user' => $this->student])
            ->get('/student/learning-hub');

        $response->assertOk();
        $response->assertSeeText('MathVerse Adventure');
        $response->assertSee('Endless Adventure');
        $response->assertSee('Weak Skill Rescue');
        $response->assertSee('All Topics');
        $response->assertSee('Ratios');
    }

    public function test_practice_page_receives_only_a_safe_public_question(): void
    {
        $practice = $this->mock(AdaptivePracticeService::class);
        $practice->shouldReceive('startOrResume')
            ->once()
            ->with($this->student, 'adventure', null)
            ->andReturn($this->practiceState());

        $response = $this->withSession(['supabase_user' => $this->student])
            ->get('/student/learning-hub/practice?mode=adventure');

        $response->assertOk();
        $response->assertSee('Solve for x: x + 8 = 13');
        $response->assertSee('Endless Adventure');
        $response->assertDontSee('"correct_answer"', false);
        $response->assertDontSee('x = 5', false);
    }

    public function test_student_can_open_a_single_curriculum_topic_in_focus_mode(): void
    {
        $state = $this->practiceState();
        $state['session']['mode'] = 'focus';
        $state['session']['focus_competency_key'] = 'g6-ratios';
        $state['question']['competency_key'] = 'g6-ratios';
        $state['question']['competency_title'] = 'Ratios';
        $state['question']['session']['mode'] = 'focus';
        $state['mode_label'] = 'Topic Focus';

        $practice = $this->mock(AdaptivePracticeService::class);
        $practice->shouldReceive('startOrResume')
            ->once()
            ->with($this->student, 'focus', 'g6-ratios')
            ->andReturn($state);

        $response = $this->withSession(['supabase_user' => $this->student])
            ->get('/student/learning-hub/practice?mode=focus&topic=g6-ratios');

        $response->assertOk();
        $response->assertSee('Topic Focus');
        $response->assertSee('Focused on your chosen topic');
        $response->assertSee('Ratios');
    }

    public function test_answer_endpoint_returns_adaptive_feedback(): void
    {
        $questionId = '22222222-2222-4222-8222-222222222222';
        $practice = $this->mock(AdaptivePracticeService::class);
        $practice->shouldReceive('submitAnswer')
            ->once()
            ->with($this->student, $questionId, '5', 1200)
            ->andReturn([
                'correct' => true,
                'correct_answer' => '5',
                'explanation' => 'Subtract 8 from both sides.',
                'xp_awarded' => 14,
                'mastery' => 18,
                'mastery_status' => 'Learning',
            ]);

        $response = $this->withSession(['supabase_user' => $this->student])
            ->postJson("/student/learning-hub/questions/{$questionId}/answer", [
                'answer' => '5',
                'response_ms' => 1200,
            ]);

        $response->assertOk()
            ->assertJsonPath('correct', true)
            ->assertJsonPath('xp_awarded', 14)
            ->assertJsonPath('mastery_status', 'Learning');
    }

    private function hubState(): array
    {
        $skill = [
            'key' => 'g6-ratios',
            'title' => 'Ratios',
            'world' => 'Number Nexus',
            'icon' => 'fa-code-compare',
            'color' => '#22d3ee',
            'term' => 'First Term',
            'strand' => 'Number and Algebra',
            'summary' => 'Describe and apply ratios.',
            'mastery' => 35,
            'difficulty' => 2,
            'attempts' => 8,
            'accuracy' => 75,
            'status' => 'Learning',
            'next_review_at' => null,
        ];

        return [
            'configured' => true,
            'grade' => 6,
            'skills' => [$skill],
            'terms' => [['label' => 'First Term', 'skills' => [$skill]]],
            'recommended' => $skill,
            'average_mastery' => 35,
            'mastered_count' => 0,
            'daily_answered' => 3,
            'daily_goal' => 10,
            'daily_percent' => 30,
            'streak' => 2,
            'xp' => 110,
            'level' => 1,
            'trophies' => 0,
            'xp_in_level' => 110,
            'xp_per_level' => 250,
            'active_session' => null,
        ];
    }

    private function practiceState(): array
    {
        return [
            'session' => [
                'id' => '33333333-3333-4333-8333-333333333333',
                'mode' => 'adventure',
                'questions_answered' => 0,
                'correct_answers' => 0,
                'xp_earned' => 0,
                'current_combo' => 0,
                'max_combo' => 0,
            ],
            'question' => [
                'id' => '22222222-2222-4222-8222-222222222222',
                'session_id' => '33333333-3333-4333-8333-333333333333',
                'prompt' => 'Solve for x: x + 8 = 13',
                'answer_type' => 'number',
                'options' => [],
                'competency_key' => 'g6-expressions',
                'competency_title' => 'Expression Engine',
                'world' => 'Equation Station',
                'icon' => 'fa-superscript',
                'color' => '#34d399',
                'difficulty' => 1,
                'sequence' => 1,
                'mission' => 1,
                'mission_position' => 1,
                'revealed_hints' => [],
                'hints_used' => 0,
                'has_more_hints' => true,
                'session' => [
                    'id' => '33333333-3333-4333-8333-333333333333',
                    'mode' => 'adventure',
                    'questions_answered' => 0,
                    'correct_answers' => 0,
                    'xp_earned' => 0,
                    'current_combo' => 0,
                    'max_combo' => 0,
                ],
            ],
            'profile' => ['xp' => 0, 'level' => 1, 'trophies' => 0],
            'mode_label' => 'Endless Adventure',
            'daily_goal' => 10,
        ];
    }
}
