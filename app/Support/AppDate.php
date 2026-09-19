<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Convert database instants (UTC when no offset is present) to portal time. */
final class AppDate
{
    public static function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Manila');
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (!$value instanceof DateTimeInterface && (!is_string($value) || trim($value) === '')) {
            return null;
        }

        try {
            return ($value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value, 'UTC'))->setTimezone(self::timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function format(mixed $value, string $format, string $fallback = 'N/A'): string
    {
        return self::parse($value)?->format($format) ?? $fallback;
    }

    public static function relative(mixed $value, string $fallback = 'Unavailable'): string
    {
        return self::parse($value)?->diffForHumans() ?? $fallback;
    }

    public static function countsByDay(array $rows, string $column = 'created_at'): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $day = self::parse($row[$column] ?? null)?->toDateString();
            if ($day !== null) $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        return $counts;
    }

    /** A date filter is a local calendar day, not midnight UTC. */
    public static function dayBoundaryUtc(string $day, bool $exclusiveEnd = false): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(self::timezone()));
        if (!$date || $date->format('Y-m-d') !== $day) return null;
        $boundary = CarbonImmutable::createFromFormat('!Y-m-d', $day, self::timezone());
        if ($exclusiveEnd) $boundary = $boundary->addDay();
        return $boundary->utc()->toIso8601String();
    }
}
