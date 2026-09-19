<?php

namespace App\Support;

final class SafePath
{
    public static function normalize(mixed $value): ?string
    {
        $path = trim((string) ($value ?? ''));
        if ($path === ''
            || strlen($path) > 2048
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
        ) {
            return null;
        }

        $decoded = $path;
        for ($pass = 0; $pass < 3; $pass++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (str_starts_with($decoded, '//')
            || str_contains($decoded, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
        ) {
            return null;
        }

        $parts = parse_url($path);
        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $path;
    }
}
