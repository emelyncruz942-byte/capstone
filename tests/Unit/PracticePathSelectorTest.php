<?php

namespace Tests\Unit;

use App\Support\PracticePathSelector;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PracticePathSelectorTest extends TestCase
{
    private array $catalog = [
        ['key' => 'addition', 'title' => 'Addition'],
        ['key' => 'fractions', 'title' => 'Fractions'],
        ['key' => 'geometry', 'title' => 'Geometry'],
    ];

    public function test_it_rotates_through_unstarted_skills_without_immediate_repetition(): void
    {
        $selector = new PracticePathSelector();

        $selected = $selector->select($this->catalog, [], 'addition', 0);

        $this->assertSame('fractions', $selected['key']);
    }

    public function test_review_mode_selects_the_lowest_practiced_mastery(): void
    {
        $selector = new PracticePathSelector();
        $mastery = [
            ['competency_key' => 'addition', 'attempts' => 8, 'mastery_score' => 72],
            ['competency_key' => 'fractions', 'attempts' => 5, 'mastery_score' => 24],
            ['competency_key' => 'geometry', 'attempts' => 0, 'mastery_score' => 0],
        ];

        $selected = $selector->select($this->catalog, $mastery, null, 10, 'review');

        $this->assertSame('fractions', $selected['key']);
    }

    public function test_due_spaced_review_wins_over_a_new_skill(): void
    {
        $selector = new PracticePathSelector();
        $now = CarbonImmutable::parse('2026-09-01 08:00:00 UTC');
        $mastery = [
            [
                'competency_key' => 'addition',
                'attempts' => 5,
                'mastery_score' => 60,
                'next_review_at' => '2026-09-01 07:00:00 UTC',
            ],
        ];

        $selected = $selector->select($this->catalog, $mastery, null, 5, 'adventure', $now);

        $this->assertSame('addition', $selected['key']);
    }
}
