<?php

namespace App\Support;

final class WebPushEndpoint
{
    public static function isAllowed(mixed $value, array $allowedHosts): bool
    {
        $endpoint = trim((string) ($value ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 2048) {
            return false;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || empty($parts['path'])
            || $parts['path'] === '/'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        foreach ($allowedHosts as $allowedHost) {
            $pattern = strtolower(trim((string) $allowedHost));
            if ($pattern === '') {
                continue;
            }

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.')
                && str_ends_with($host, substr($pattern, 1))
                && $host !== substr($pattern, 2)
            ) {
                return true;
            }
        }

        return false;
    }
}
