<?php

namespace Tests\Unit;

use App\Support\PracticeProblemGenerator;
use PHPUnit\Framework\TestCase;

class PracticeProblemGeneratorTest extends TestCase
{
    public function test_every_new_curriculum_topic_has_a_working_problem_template(): void
    {
        $generator = new PracticeProblemGenerator();
        $keys = [];
        $expectedCounts = [1 => 15, 2 => 17, 3 => 22, 4 => 19, 5 => 20, 6 => 16];

        for ($grade = 1; $grade <= 6; $grade++) {
            $catalog = $generator->catalogForGrade($grade);

            $this->assertCount($expectedCounts[$grade], $catalog);
            foreach ($catalog as $index => $competency) {
                $keys[] = $competency['key'];
                $this->assertContains($competency['term'], ['First Term', 'Second Term', 'Third Term']);
                $this->assertContains($competency['strand'], [
                    'Number and Algebra',
                    'Measurement and Geometry',
                    'Data and Probability',
                ]);
                $this->assertNotSame('', $competency['summary']);

                for ($difficulty = 1; $difficulty <= 5; $difficulty++) {
                    $problem = $generator->generate(
                        $grade,
                        $competency['key'],
                        $difficulty,
                        ($grade * 10000) + ($difficulty * 1000) + $index
                    );

                    $this->assertNotSame('', $problem['prompt']);
                    $this->assertContains($problem['answer_type'], ['number', 'choice']);
                    $this->assertNotSame('', $problem['correct_answer']);
                    $this->assertNotEmpty($problem['hints']);
                    $this->assertNotSame('', $problem['explanation']);
                    $this->assertSame($difficulty, $problem['difficulty']);
                    $this->assertSame($competency['title'], $problem['title']);

                    if ($problem['answer_type'] === 'choice') {
                        $this->assertGreaterThanOrEqual(3, count($problem['options']));
                        $this->assertContains($problem['correct_answer'], $problem['options']);
                    }
                }
            }
        }

        $this->assertCount(109, array_unique($keys));
    }

    public function test_a_seed_reproduces_the_same_reviewed_problem(): void
    {
        $generator = new PracticeProblemGenerator();

        $first = $generator->generate(6, 'g6-percentages', 4, 8675309);
        $second = $generator->generate(6, 'g6-percentages', 4, 8675309);

        $this->assertSame($first, $second);
    }

    public function test_choice_questions_always_include_the_correct_answer(): void
    {
        $generator = new PracticeProblemGenerator();

        foreach (['g1-shapes-2d', 'g3-line-relationships', 'g4-angles'] as $index => $key) {
            $grade = [1, 3, 4][$index];
            $problem = $generator->generate($grade, $key, 2, 42 + $index);

            $this->assertSame('choice', $problem['answer_type']);
            $this->assertContains($problem['correct_answer'], $problem['options']);
        }
    }

    public function test_problem_variations_stay_inside_the_published_curriculum_boundaries(): void
    {
        $generator = new PracticeProblemGenerator();

        for ($seed = 0; $seed < 25; $seed++) {
            $pictograph = $generator->generate(1, 'g1-pictographs', 5, $seed);
            $this->assertStringContainsString('★ = 1 learner', $pictograph['prompt']);
            $this->assertGreaterThanOrEqual(3, substr_count($pictograph['prompt'], "\n"));
            $this->assertMatchesRegularExpression(
                '/more|additional|altogether|all three|twice|missing/iu',
                $pictograph['prompt']
            );
            $this->assertStringNotContainsString('How many learners does it represent?', $pictograph['prompt']);

            $multiplication = $generator->generate(2, 'g2-equal-groups', 5, $seed);
            $this->assertMatchesRegularExpression('/groups|rows|packs|array|seats|jumps/iu', $multiplication['prompt']);
            $this->assertLessThanOrEqual(115, (int) $multiplication['correct_answer']);

            $largeOperation = $generator->generate(4, 'g4-multi-add', 5, $seed);
            $this->assertLessThanOrEqual(1000000, (int) $largeOperation['correct_answer']);
        }

        $translation = $generator->generate(3, 'g3-translation', 3, 2026);
        $this->assertSame('choice', $translation['answer_type']);
        $this->assertStringContainsString(' and ', $translation['prompt']);

        $volume = $generator->generate(5, 'g5-volume', 3, 2026);
        $this->assertStringContainsString('unit cubes', $volume['prompt']);
        $this->assertStringNotContainsString('cubic centimeters', $volume['prompt']);
    }

    public function test_ordinal_questions_require_position_reasoning_instead_of_revealing_the_answer(): void
    {
        $generator = new PracticeProblemGenerator();

        foreach ([1 => 'g1-ordinals-10', 2 => 'g2-ordinals-20', 3 => 'g3-ordinals-100'] as $grade => $key) {
            for ($seed = 0; $seed < 60; $seed++) {
                $problem = $generator->generate($grade, $key, 5, $seed);

                $this->assertSame('choice', $problem['answer_type']);
                $this->assertStringNotContainsString($problem['correct_answer'], $problem['prompt']);
                $this->assertDoesNotMatchRegularExpression(
                    '/ordinal form|names position|counting number/iu',
                    $problem['prompt']
                );
                $this->assertMatchesRegularExpression(
                    '/front|ahead|passed|moved|right end|behind|full rows/iu',
                    $problem['prompt']
                );
            }
        }
    }

    public function test_giveaway_fraction_and_probability_prompts_require_complete_results(): void
    {
        $generator = new PracticeProblemGenerator();
        $topics = [
            [3, 'g3-similar-fractions'],
            [4, 'g4-dissimilar-fractions'],
            [5, 'g5-fraction-multiply'],
            [5, 'g5-fraction-divide'],
            [5, 'g5-theoretical-probability'],
            [6, 'g6-fraction-operations'],
        ];

        foreach ($topics as [$grade, $key]) {
            for ($seed = 0; $seed < 20; $seed++) {
                $problem = $generator->generate($grade, $key, 4, $seed);

                $this->assertSame('choice', $problem['answer_type']);
                $this->assertDoesNotMatchRegularExpression(
                    '/what (?:is )?the numerator|write only the numerator|before simplifying/iu',
                    $problem['prompt']
                );
                $this->assertContains($problem['correct_answer'], $problem['options']);
            }
        }
    }

    public function test_hard_modes_change_the_reasoning_structure_instead_of_only_increasing_values(): void
    {
        $generator = new PracticeProblemGenerator();

        for ($seed = 0; $seed < 25; $seed++) {
            $properties = $generator->generate(3, 'g3-multiplication-properties', 5, $seed);
            $this->assertMatchesRegularExpression('/associative|distributive|learner rewrites/iu', $properties['prompt']);

            $divisibility = $generator->generate(5, 'g5-divisibility', 5, $seed);
            $this->assertDoesNotMatchRegularExpression('/^Is \d+ divisible by/iu', $divisibility['prompt']);
            $this->assertMatchesRegularExpression(
                '/fewest|smallest|how many more books|exception|counterexample|cannot be|only one|exact multiple|can be split/iu',
                $divisibility['prompt']
            );

            $primeComposite = $generator->generate(5, 'g5-prime-composite', 5, $seed);
            $this->assertDoesNotMatchRegularExpression('/^Is \d+ prime or composite/iu', $primeComposite['prompt']);
            $this->assertMatchesRegularExpression(
                '/choice|prime|composite|factor|evidence|rectangle|rectangular|disproves/iu',
                $primeComposite['prompt']
            );

            $gmdas = $generator->generate(5, 'g5-order-operations', 5, $seed);
            preg_match_all('/[+−×÷]/u', $gmdas['prompt'], $gmdasOperations);
            $this->assertGreaterThanOrEqual(3, count($gmdasOperations[0]));

            $exponents = $generator->generate(6, 'g6-exponents-gemdas', 5, $seed);
            preg_match_all('/[+−×÷]/u', $exponents['prompt'], $exponentOperations);
            $this->assertGreaterThanOrEqual(2, count($exponentOperations[0]));

            $decimals = $generator->generate(6, 'g6-decimal-operations', 5, $seed);
            $this->assertMatchesRegularExpression('/\d+\.\d{4}/u', $decimals['prompt']);
            preg_match_all('/[+−×÷]/u', $decimals['prompt'], $decimalOperations);
            $this->assertTrue(
                count($decimalOperations[0]) >= 3
                || preg_match('/adds .* multiplies .* subtracts/iu', $decimals['prompt']) === 1
            );

            $mixedFractions = $generator->generate(6, 'g6-fraction-operations', 5, $seed);
            $this->assertMatchesRegularExpression('/\d+ \d+\/\d+/u', $mixedFractions['prompt']);
        }
    }

    public function test_every_topic_produces_many_question_variations(): void
    {
        $generator = new PracticeProblemGenerator();

        for ($grade = 1; $grade <= 6; $grade++) {
            foreach ($generator->catalogForGrade($grade) as $competency) {
                $prompts = [];
                $presentationForms = [];
                $coreForms = [];

                for ($seed = 0; $seed < 60; $seed++) {
                    $problem = $generator->generate($grade, $competency['key'], 3, $seed);
                    $prompts[$problem['prompt']] = true;
                    $presentationForms[$this->normalizedPromptForm($problem['prompt'])] = true;
                    $coreForms[$this->normalizedPromptForm($this->corePrompt($problem['prompt']))] = true;

                    $this->assertDoesNotMatchRegularExpression(
                        '/^(?:Find the missing value|Try this new example|Solve carefully, then verify your result|Challenge variation|Use the definition to decide|Compare every choice carefully|Choose the best mathematical answer|Concept challenge):/u',
                        $problem['prompt'],
                        "{$competency['key']} disguises repetition with a generic opener."
                    );

                    if ($problem['answer_type'] === 'choice') {
                        $this->assertGreaterThanOrEqual(
                            3,
                            count($problem['options']),
                            "{$competency['key']} generated too few meaningful choices."
                        );
                        $this->assertSame(
                            $problem['options'],
                            array_values(array_unique($problem['options'])),
                            "{$competency['key']} generated duplicate choices."
                        );
                        $this->assertContains($problem['correct_answer'], $problem['options']);
                    }
                }

                $this->assertGreaterThanOrEqual(
                    15,
                    count($prompts),
                    "{$competency['key']} repeats too few distinct questions."
                );
                $this->assertGreaterThanOrEqual(
                    3,
                    count($presentationForms),
                    "{$competency['key']} repeats too few presentation styles."
                );
                $this->assertGreaterThanOrEqual(
                    3,
                    count($coreForms),
                    "{$competency['key']} repeats too few underlying problem structures."
                );
            }
        }
    }

    private function corePrompt(string $prompt): string
    {
        return preg_replace(
            '/^(?:Find the missing value|Try this new example|Solve carefully, then verify your result|Challenge variation|Use the definition to decide|Compare every choice carefully|Choose the best mathematical answer|Concept challenge):\s*/u',
            '',
            trim($prompt)
        ) ?? trim($prompt);
    }

    private function normalizedPromptForm(string $prompt): string
    {
        return preg_replace('/-?\d+(?:\.\d+)?(?:st|nd|rd|th)?/u', '{n}', $prompt) ?? $prompt;
    }
}
