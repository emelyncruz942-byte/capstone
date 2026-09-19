<?php

namespace Tests\Unit;

use App\Support\QuizAnswer;
use PHPUnit\Framework\TestCase;

class QuizAnswerTest extends TestCase
{
    public function test_it_resolves_the_current_zero_based_answer_index(): void
    {
        $answer = QuizAnswer::resolve([
            'choice1' => '12',
            'choice2' => '18',
            'choice3' => '24',
            'choice4' => '30',
            'correct_answer' => '2',
        ]);

        $this->assertSame(2, $answer['index']);
        $this->assertSame('24', $answer['option']);
        $this->assertSame('C. 24', $answer['label']);
    }

    public function test_it_resolves_legacy_answer_text(): void
    {
        $answer = QuizAnswer::resolve([
            'choice1' => '3:2',
            'choice2' => '2:3',
            'choice3' => '4:2',
            'choice4' => '6:4',
            'correct_answer' => '3:2',
        ]);

        $this->assertSame(0, $answer['index']);
        $this->assertSame('A. 3:2', $answer['label']);
    }

    public function test_it_preserves_an_unmatched_legacy_value(): void
    {
        $answer = QuizAnswer::resolve([
            'choice1' => 'One',
            'choice2' => 'Two',
            'correct_answer' => 'Legacy answer',
        ]);

        $this->assertNull($answer['index']);
        $this->assertSame('Legacy answer', $answer['label']);
    }
}
