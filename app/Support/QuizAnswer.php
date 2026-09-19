<?php

namespace App\Support;

final class QuizAnswer
{
    /**
     * Resolve both current zero-based answer indexes and legacy answer text.
     *
     * @return array{index: int|null, option: string, label: string}
     */
    public static function resolve(array $question): array
    {
        $choices = self::choices($question);
        $stored = trim((string) ($question['correct_answer'] ?? ''));
        $index = null;

        if (preg_match('/^\d+$/', $stored) === 1) {
            $candidate = (int) $stored;
            if (array_key_exists($candidate, $choices)) {
                $index = $candidate;
            }
        }

        if ($index === null) {
            $matched = array_search($stored, $choices, true);
            $index = $matched === false ? null : (int) $matched;
        }

        $option = $index !== null
            ? $choices[$index]
            : ($stored !== '' ? $stored : 'Not specified');
        $letter = $index !== null && $index < 26
            ? chr(65 + $index)
            : null;

        return [
            'index' => $index,
            'option' => $option,
            'label' => $letter !== null ? "{$letter}. {$option}" : $option,
        ];
    }

    /** @return array<int, string> */
    public static function choices(array $question): array
    {
        $choices = [];

        for ($number = 1; $number <= 6; $number++) {
            $choice = trim((string) ($question["choice{$number}"] ?? ''));
            if ($choice !== '') {
                $choices[] = $choice;
            }
        }

        return $choices;
    }
}
