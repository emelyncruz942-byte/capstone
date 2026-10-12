<?php

namespace Tests\Feature;

use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class QuizReportRevisionFlowTest extends TestCase
{
    private const TEACHER_ID = '110e8400-e29b-41d4-a716-446655440000';

    private const OTHER_TEACHER_ID = '220e8400-e29b-41d4-a716-446655440000';

    private const REPORT_ID = '330e8400-e29b-41d4-a716-446655440000';

    private const QUIZ_ID = '440e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withViewErrors([]);
    }

    public function test_teacher_report_list_is_scoped_to_quizzes_the_teacher_created(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelectPage')
                ->once()
                ->with('quiz_reports', '*', [
                    'quiz_creator_id' => self::TEACHER_ID,
                    'status' => 'pending',
                    'order' => 'created_at.asc',
                ], 20, 0)
                ->andReturn(['data' => [], 'total' => 0]);
            $mock->shouldReceive('adminCount')
                ->once()->with('quiz_reports', [
                    'quiz_creator_id' => self::TEACHER_ID,
                    'status' => 'pending',
                ])->andReturn(0);
            $mock->shouldReceive('adminCount')
                ->once()->with('quiz_reports', [
                    'quiz_creator_id' => self::TEACHER_ID,
                    'status' => 'reviewed',
                ])->andReturn(1);
            $mock->shouldReceive('adminCount')
                ->once()->with('quiz_reports', [
                    'quiz_creator_id' => self::TEACHER_ID,
                    'status' => 'dismissed',
                ])->andReturn(0);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->get('/teacher/quiz-reports');

        $response->assertOk()
            ->assertSee('Reported Quizzes')
            ->assertSee('There are no quiz reports awaiting administrator review.');
    }

    public function test_teacher_can_open_details_only_through_creator_scoped_lookup(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')->andReturnUsing(
                function (string $table, string $columns, array $filters): array {
                    return match ($table) {
                        'quiz_reports' => $filters === [
                            'id' => self::REPORT_ID,
                            'quiz_creator_id' => self::TEACHER_ID,
                        ] ? [$this->pendingReport()] : [],
                        'quizzes' => [[
                            'id' => self::QUIZ_ID,
                            'teacher_id' => self::TEACHER_ID,
                            'topic' => 'Updated Fraction Operations',
                            'grade_level' => 5,
                            'version' => 2,
                            'visibility' => 'shared',
                            'updated_at' => '2026-10-12T01:00:00Z',
                        ]],
                        'quiz_questions' => [[
                            'id' => 17,
                            'position' => 2,
                            'question' => 'This corrected question must not replace the report snapshot.',
                        ]],
                        'profiles' => [[
                            'id' => self::OTHER_TEACHER_ID,
                            'first_name' => 'Grace',
                            'last_name' => 'Teacher',
                            'role' => 'teacher',
                        ]],
                        default => [],
                    };
                }
            );
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->get('/teacher/quiz-reports/'.self::REPORT_ID);

        $response->assertOk()
            ->assertSee('Fraction Operations')
            ->assertSee('What is one half plus one fourth?')
            ->assertSee('The expected answer should be three fourths.')
            ->assertDontSee('Updated Fraction Operations')
            ->assertDontSee('This corrected question must not replace the report snapshot.')
            ->assertSee('Mark as Revised')
            ->assertSee('This does not close or remove the report');
    }

    public function test_teacher_cannot_open_a_report_for_another_creators_quiz(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('quiz_reports', '*', [
                    'id' => self::REPORT_ID,
                    'quiz_creator_id' => self::TEACHER_ID,
                ])
                ->andReturn([]);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->get('/teacher/quiz-reports/'.self::REPORT_ID);

        $response->assertRedirect('/teacher/quiz-reports')
            ->assertSessionHas('error', 'That quiz report is not available to your account.');
    }

    public function test_teacher_marking_revised_uses_server_rpc_and_leaves_report_pending(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminRpcResult')
                ->once()
                ->with('mark_quiz_report_revised', [
                    'p_report_id' => self::REPORT_ID,
                    'p_teacher_id' => self::TEACHER_ID,
                ])
                ->andReturn([
                    'data' => [[
                        'report_id' => self::REPORT_ID,
                        'report_status' => 'pending',
                        'report_revised_at' => '2026-10-12T02:00:00Z',
                        'revision_changed' => true,
                    ]],
                    'error' => null,
                    'status' => 200,
                ]);
        });

        $response = $this->withSession(['supabase_user' => $this->teacher()])
            ->post('/teacher/quiz-reports/'.self::REPORT_ID.'/revised');

        $response->assertRedirect('/teacher/quiz-reports/'.self::REPORT_ID)
            ->assertSessionHas(
                'success',
                'Marked as revised. The report remains open for administrator review.'
            );
    }

    public function test_deleted_quiz_must_be_restored_before_teacher_can_mark_report_revised(): void
    {
        $response = $this->view('teacher.quiz-reports.show', [
            'user' => $this->teacher(),
            'report' => array_merge($this->pendingReport(), [
                'quiz' => ['id' => self::QUIZ_ID, 'deleted_at' => '2026-10-12T03:00:00Z'],
                'quiz_available' => false,
                'quiz_topic_display' => 'Fraction Operations',
                'quiz_grade_display' => 5,
                'reporter_name' => 'Grace Teacher',
                'reviewer_name' => null,
                'question_text_display' => 'What is one half plus one fourth?',
                'question_label' => 'Question 2: What is one half plus one fourth?',
            ]),
        ]);

        $response->assertSee('Restore the quiz from Trash before marking it revised.')
            ->assertDontSee('Mark as Revised');
    }

    public function test_admin_report_details_distinguish_teacher_revision_from_admin_review(): void
    {
        $response = $this->view('admin.quiz-reports.show', [
            'user' => ['id' => '550e8400-e29b-41d4-a716-446655440000'],
            'nextReport' => null,
            'report' => array_merge($this->pendingReport(), [
                'quiz' => ['id' => self::QUIZ_ID],
                'quiz_available' => true,
                'quiz_topic_display' => 'Fraction Operations',
                'quiz_grade_display' => 5,
                'quiz_creator_id_display' => self::TEACHER_ID,
                'quiz_creator_name' => 'Tess Teacher',
                'reporter_name' => 'Grace Teacher',
                'reviewer_name' => null,
                'question_text_display' => 'What is one half plus one fourth?',
                'question_label' => 'Question 2: What is one half plus one fourth?',
                'revised_at' => '2026-10-12T02:00:00Z',
                'revised_by' => self::TEACHER_ID,
            ]),
        ]);

        $response->assertSee('Revised by teacher')
            ->assertSee('Teacher Revision Status')
            ->assertSee('It remains in the active queue until an administrator reviews or dismisses it.');
    }

    public function test_admin_report_details_keep_the_original_report_snapshot_after_a_revision(): void
    {
        $this->withoutMiddleware();

        $this->mock(SupabaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adminSelect')->andReturnUsing(
                function (string $table, string $columns, array $filters): array {
                    if ($table === 'quiz_reports') {
                        return isset($filters['id']) && is_string($filters['id'])
                            ? [$this->pendingReport()]
                            : [];
                    }

                    return match ($table) {
                        'quizzes' => [[
                            'id' => self::QUIZ_ID,
                            'teacher_id' => self::TEACHER_ID,
                            'topic' => 'Updated Fraction Operations',
                            'grade_level' => 6,
                            'deleted_at' => null,
                        ]],
                        'quiz_questions' => [[
                            'id' => 17,
                            'position' => 4,
                            'question' => 'This is the corrected current question.',
                        ]],
                        'profiles' => [[
                            'id' => self::TEACHER_ID,
                            'first_name' => 'Tess',
                            'last_name' => 'Teacher',
                            'role' => 'teacher',
                        ], [
                            'id' => self::OTHER_TEACHER_ID,
                            'first_name' => 'Grace',
                            'last_name' => 'Teacher',
                            'role' => 'teacher',
                        ]],
                        default => [],
                    };
                }
            );
        });

        $response = $this->withSession(['supabase_user' => [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'role' => 'admin',
        ]])->get('/admin/quiz-reports/'.self::REPORT_ID);

        $response->assertOk()
            ->assertSee('Fraction Operations')
            ->assertSee('What is one half plus one fourth?')
            ->assertDontSee('Updated Fraction Operations')
            ->assertDontSee('This is the corrected current question.');
    }

    /** @return array<string, mixed> */
    private function pendingReport(): array
    {
        return [
            'id' => self::REPORT_ID,
            'quiz_id' => self::QUIZ_ID,
            'quiz_creator_id' => self::TEACHER_ID,
            'reporter_id' => self::OTHER_TEACHER_ID,
            'question_id' => 17,
            'question_text' => 'What is one half plus one fourth?',
            'quiz_topic' => 'Fraction Operations',
            'quiz_grade_level' => 5,
            'reason' => 'incorrect_answer',
            'details' => 'The expected answer should be three fourths.',
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'revised_at' => null,
            'revised_by' => null,
            'created_at' => '2026-10-11T23:00:00Z',
        ];
    }

    /** @return array{id: string, role: string, email: string} */
    private function teacher(): array
    {
        return [
            'id' => self::TEACHER_ID,
            'role' => 'teacher',
            'email' => 'teacher@example.test',
        ];
    }
}
