<?php

namespace Tests\Unit;

use App\Services\AdaptivePracticeService;
use App\Support\PracticeProblemGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AdaptivePracticeVarietyTest extends TestCase
{
    public function test_every_topic_focus_path_avoids_recent_examples_and_immediate_forms(): void
    {
        $generator = new PracticeProblemGenerator();
        $service = $this->serviceWithGenerator($generator);
        $freshProblem = new ReflectionMethod($service, 'freshProblem');
        $isFreshProblem = new ReflectionMethod($service, 'isFreshProblem');
        $corePrompt = new ReflectionMethod($service, 'corePrompt');
        $promptForm = new ReflectionMethod($service, 'promptForm');

        for ($grade = 1; $grade <= 6; $grade++) {
            foreach ($generator->catalogForGrade($grade) as $competency) {
                $recent = [];

                for ($sequence = 1; $sequence <= 16; $sequence++) {
                    $problem = $freshProblem->invoke(
                        $service,
                        $grade,
                        $competency['key'],
                        4,
                        "{$competency['key']}-variety-session",
                        $sequence,
                        $recent
                    );
                    $core = $corePrompt->invoke($service, $problem['prompt']);

                    $this->assertTrue(
                        $isFreshProblem->invoke($service, $problem, $recent),
                        "{$competency['key']} reused a recent example."
                    );

                    if ($recent !== []) {
                        $previousCore = $corePrompt->invoke($service, $recent[0]['prompt']);
                        $this->assertNotSame(
                            $promptForm->invoke($service, $previousCore),
                            $promptForm->invoke($service, $core),
                            "{$competency['key']} immediately reused a problem structure."
                        );
                    }

                    array_unshift($recent, [
                        'competency_key' => $competency['key'],
                        'prompt' => $problem['prompt'],
                    ]);
                    $recent = array_slice($recent, 0, 12);
                }
            }
        }
    }

    public function test_focused_practice_avoids_recent_static_question_repeats(): void
    {
        $service = $this->serviceWithGenerator();
        $freshProblem = new ReflectionMethod($service, 'freshProblem');
        $corePrompt = new ReflectionMethod($service, 'corePrompt');
        $recent = [];

        for ($sequence = 1; $sequence <= 36; $sequence++) {
            $problem = $freshProblem->invoke(
                $service,
                1,
                'g1-shapes-2d',
                3,
                'static-variety-session',
                $sequence,
                $recent
            );
            $core = $corePrompt->invoke($service, $problem['prompt']);
            $recentCores = array_map(
                fn (array $question): string => $corePrompt->invoke($service, $question['prompt']),
                $recent
            );

            $this->assertNotContains($core, $recentCores);

            array_unshift($recent, [
                'competency_key' => 'g1-shapes-2d',
                'prompt' => $problem['prompt'],
            ]);
            $recent = array_slice($recent, 0, 20);
        }
    }

    public function test_focused_practice_changes_values_and_rotates_problem_structures(): void
    {
        $service = $this->serviceWithGenerator();
        $freshProblem = new ReflectionMethod($service, 'freshProblem');
        $corePrompt = new ReflectionMethod($service, 'corePrompt');
        $promptForm = new ReflectionMethod($service, 'promptForm');
        $numberSignature = new ReflectionMethod($service, 'numberSignature');
        $recent = [];

        for ($sequence = 1; $sequence <= 28; $sequence++) {
            $problem = $freshProblem->invoke(
                $service,
                2,
                'g2-add-1000',
                4,
                'numeric-variety-session',
                $sequence,
                $recent
            );
            $core = $corePrompt->invoke($service, $problem['prompt']);
            $signature = $numberSignature->invoke($service, $core);
            $form = $promptForm->invoke($service, $core);

            foreach ($recent as $index => $question) {
                $recentCore = $corePrompt->invoke($service, $question['prompt']);
                if ($form === $promptForm->invoke($service, $recentCore)) {
                    $this->assertNotSame(
                        $signature,
                        $numberSignature->invoke($service, $recentCore),
                        'A recent generated value set was reused for the same structure.'
                    );
                }

                if ($index === 0) {
                    $this->assertNotSame(
                        $form,
                        $promptForm->invoke($service, $recentCore),
                        'The immediately previous underlying problem structure was reused.'
                    );
                }
            }

            array_unshift($recent, [
                'competency_key' => 'g2-add-1000',
                'prompt' => $problem['prompt'],
            ]);
            $recent = array_slice($recent, 0, 20);
        }
    }

    public function test_pictograph_fingerprints_include_the_icon_values(): void
    {
        $service = $this->serviceWithGenerator();
        $numberSignature = new ReflectionMethod($service, 'numberSignature');

        $first = $numberSignature->invoke(
            $service,
            "Pictograph — Key: ★ = 1 learner\nMango: ★★\nBanana: ★★★★"
        );
        $second = $numberSignature->invoke(
            $service,
            "Pictograph — Key: ★ = 1 learner\nMango: ★★★\nBanana: ★★★★★"
        );

        $this->assertNotSame($first, $second);
        $this->assertContains('icons:2', $first);
        $this->assertContains('icons:5', $second);
    }

    private function serviceWithGenerator(
        ?PracticeProblemGenerator $generator = null
    ): AdaptivePracticeService
    {
        $reflection = new ReflectionClass(AdaptivePracticeService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('generator')->setValue(
            $service,
            $generator ?? new PracticeProblemGenerator()
        );

        return $service;
    }
}
