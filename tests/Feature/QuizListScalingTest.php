<?php

namespace Tests\Feature;

use App\Services\SupabaseService;
use Mockery\MockInterface;
use Tests\TestCase;

class QuizListScalingTest extends TestCase
{
    private const OWNER_ID = '11111111-1111-4111-8111-111111111111';

    public function test_admin_can_open_a_quiz_list_with_more_ids_than_one_safe_filter_can_hold(): void
    {
        $this->withoutMiddleware();
        $quizzes = $this->quizRows(109);
        $observedIds = [];

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($quizzes, &$observedIds): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('quizzes', '*', [
                    'teacher_id' => self::OWNER_ID,
                    'order' => 'created_at.desc',
                ])
                ->andReturn($quizzes);
            $this->expectQuestionCountBatches($mock, 3, $observedIds);
        });

        $response = $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/quizzes');

        $response->assertOk()
            ->assertSeeText('109 quizzes found')
            ->assertSeeText('Quiz 109');
        $this->assertSame(array_column($quizzes, 'id'), $observedIds);
    }

    public function test_teacher_quiz_list_uses_the_same_safe_question_count_batches(): void
    {
        $this->withoutMiddleware();
        $quizzes = $this->quizRows(55);
        $observedIds = [];

        $this->mock(SupabaseService::class, function (MockInterface $mock) use ($quizzes, &$observedIds): void {
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('quizzes', '*', [
                    'teacher_id' => self::OWNER_ID,
                    'order' => 'created_at.desc',
                ])
                ->andReturn($quizzes);
            $this->expectQuestionCountBatches($mock, 2, $observedIds);
            $mock->shouldReceive('adminSelect')
                ->once()
                ->with('classes', 'id,class_name,grade_level,archived_at', [
                    'teacher_id' => self::OWNER_ID,
                    'archived_at' => ['operator' => 'is', 'value' => 'null'],
                    'order' => 'class_name.asc',
                ])
                ->andReturn([]);
        });

        $response = $this->withSession(['supabase_user' => $this->user('teacher')])
            ->get('/teacher/quizzes');

        $response->assertOk()
            ->assertSeeText('55 quizzes found')
            ->assertSeeText('Quiz 55');
        $this->assertSame(array_column($quizzes, 'id'), $observedIds);
    }

    /** @param list<string> $observedIds */
    private function expectQuestionCountBatches(
        MockInterface $mock,
        int $expectedBatches,
        array &$observedIds
    ): void {
        $mock->shouldReceive('adminSelect')
            ->times($expectedBatches)
            ->withArgs(function (string $table, string $select, array $filters): bool {
                $value = $filters['quiz_id']['value'] ?? '';
                if ($table !== 'quiz_questions'
                    || $select !== 'quiz_id'
                    || !is_string($value)
                    || strlen($value) > 2000
                    || preg_match('/^\([0-9a-f,-]+\)$/i', $value) !== 1
                ) {
                    return false;
                }

                $batch = explode(',', trim($value, '()'));
                if (count($batch) > 50) {
                    return false;
                }

                return true;
            })
            ->andReturnUsing(function (string $table, string $select, array $filters) use (&$observedIds): array {
                $ids = explode(',', trim($filters['quiz_id']['value'], '()'));
                array_push($observedIds, ...$ids);

                return array_map(static fn (string $id): array => ['quiz_id' => $id], $ids);
            });
    }

    /** @return list<array<string, mixed>> */
    private function quizRows(int $count): array
    {
        $quizzes = [];
        for ($index = 1; $index <= $count; $index++) {
            $quizzes[] = [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'teacher_id' => self::OWNER_ID,
                'topic' => "Quiz {$index}",
                'grade_level' => (($index - 1) % 6) + 1,
                'visibility' => 'shared',
                'version' => 1,
                'created_at' => '2026-09-20T00:00:00Z',
            ];
        }

        return $quizzes;
    }

    /** @return array<string, mixed> */
    private function user(string $role): array
    {
        return [
            'id' => self::OWNER_ID,
            'role' => $role,
            'first_name' => 'Quiz',
            'last_name' => 'Owner',
            'email' => 'owner@example.test',
            'avatar_url' => null,
        ];
    }
}
