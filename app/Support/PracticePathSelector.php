<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class PracticePathSelector
{
    public function select(
        array $catalog,
        array $masteryRows,
        ?string $lastCompetency,
        int $questionsAnswered,
        string $mode = 'adventure',
        ?CarbonImmutable $now = null
    ): array {
        $now ??= CarbonImmutable::now();
        $mastery = array_column($masteryRows, null, 'competency_key');

        if ($mode === 'review') {
            $practiced = array_values(array_filter(
                $catalog,
                fn (array $item): bool => (int) ($mastery[$item['key']]['attempts'] ?? 0) > 0
            ));

            if ($practiced !== []) {
                usort($practiced, fn (array $a, array $b): int =>
                    ((int) ($mastery[$a['key']]['mastery_score'] ?? 0))
                    <=> ((int) ($mastery[$b['key']]['mastery_score'] ?? 0))
                );

                return $this->avoidImmediateRepeat($practiced, $lastCompetency);
            }
        }

        $due = array_values(array_filter($catalog, function (array $item) use ($mastery, $now): bool {
            $nextReview = $mastery[$item['key']]['next_review_at'] ?? null;

            return $nextReview !== null && CarbonImmutable::parse($nextReview, 'UTC')->lte($now);
        }));
        if ($due !== []) {
            usort($due, fn (array $a, array $b): int =>
                ((int) ($mastery[$a['key']]['mastery_score'] ?? 0))
                <=> ((int) ($mastery[$b['key']]['mastery_score'] ?? 0))
            );

            return $this->avoidImmediateRepeat($due, $lastCompetency);
        }

        $unstarted = array_values(array_filter(
            $catalog,
            fn (array $item): bool => !isset($mastery[$item['key']])
                || (int) ($mastery[$item['key']]['attempts'] ?? 0) === 0
        ));
        if ($unstarted !== []) {
            $index = $questionsAnswered % count($unstarted);
            $selected = $unstarted[$index];
            if ($selected['key'] === $lastCompetency && count($unstarted) > 1) {
                $selected = $unstarted[($index + 1) % count($unstarted)];
            }

            return $selected;
        }

        $ranked = $catalog;
        usort($ranked, function (array $a, array $b) use ($mastery): int {
            $aScore = (int) ($mastery[$a['key']]['mastery_score'] ?? 0);
            $bScore = (int) ($mastery[$b['key']]['mastery_score'] ?? 0);

            return ($aScore <=> $bScore)
                ?: ((int) ($mastery[$a['key']]['attempts'] ?? 0) <=> (int) ($mastery[$b['key']]['attempts'] ?? 0));
        });

        return $this->avoidImmediateRepeat($ranked, $lastCompetency);
    }

    private function avoidImmediateRepeat(array $candidates, ?string $lastCompetency): array
    {
        if (count($candidates) > 1 && $candidates[0]['key'] === $lastCompetency) {
            return $candidates[1];
        }

        return $candidates[0];
    }
}
