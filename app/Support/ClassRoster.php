<?php

namespace App\Support;

final class ClassRoster
{
    /**
     * Keep a classroom roster predictable for teachers: surname first, then
     * given name, username, and finally the immutable student identifier.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, array<string, mixed>>
     */
    public static function sortByLastName(array $members): array
    {
        usort($members, static function (array $left, array $right): int {
            $leftProfile = is_array($left['profiles'] ?? null) ? $left['profiles'] : [];
            $rightProfile = is_array($right['profiles'] ?? null) ? $right['profiles'] : [];

            foreach (['last_name', 'first_name', 'username', 'id'] as $field) {
                $leftRawValue = $leftProfile[$field] ?? '';
                $rightRawValue = $rightProfile[$field] ?? '';
                // The immutable membership ID is only a final tie-breaker.
                // It must not make a missing surname sort ahead of named
                // students merely because its UUID happens to start with A.
                if ($field === 'id') {
                    $leftRawValue = $leftRawValue ?: ($left['student_id'] ?? '');
                    $rightRawValue = $rightRawValue ?: ($right['student_id'] ?? '');
                }
                $leftValue = self::sortValue($leftRawValue);
                $rightValue = self::sortValue($rightRawValue);
                $comparison = strnatcasecmp($leftValue, $rightValue);

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return array_values($members);
    }

    private static function sortValue(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            // Unknown surnames follow named students instead of appearing at
            // the top of a teacher's roster.
            return "\xFF";
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
