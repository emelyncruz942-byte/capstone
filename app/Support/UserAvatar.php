<?php

namespace App\Support;

final class UserAvatar
{
    /** @return array{name:string, initials:string, color:int, url:?string, anonymous:bool} */
    public static function presentation(array $person): array
    {
        $first = self::text($person['first_name'] ?? '');
        $last = self::text($person['last_name'] ?? '');
        $name = trim($first.' '.$last);
        if ($name === '') {
            $name = self::text($person['display_name'] ?? $person['name'] ?? '') ?: 'User';
        }

        $anonymous = ($person['is_anonymous'] ?? false) === true
            || mb_strtolower(self::text($person['display_name'] ?? $person['name'] ?? ''), 'UTF-8') === 'anonymous student';
        if ($anonymous) $name = 'Anonymous Student';
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['User'];
        $initials = mb_substr($words[0], 0, 1, 'UTF-8');
        if (count($words) > 1) {
            $initials .= mb_substr($words[count($words) - 1], 0, 1, 'UTF-8');
        }
        $identity = $anonymous ? $name : (self::text($person['id'] ?? $person['email'] ?? '') ?: $name);
        $color = 0;
        foreach (mb_str_split(mb_strtolower($identity, 'UTF-8'), 1, 'UTF-8') as $letter) {
            $color = ($color + mb_ord($letter, 'UTF-8')) % 6;
        }

        return [
            'name' => $name,
            'initials' => $anonymous ? '' : mb_strtoupper($initials, 'UTF-8'),
            'color' => $color,
            'url' => $anonymous ? null : self::photoUrl($person['avatar_url'] ?? null),
            'anonymous' => $anonymous,
        ];
    }

    public static function photoUrl(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $url = trim($value);
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7F]/', $url)) return null;
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return null;
        $path = strtolower($parts['path'] ?? '');
        if (in_array($path, ['/default.png', '/images/default.png', '/default-avatar.png'], true)) return null;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) return $url;
        return in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && !empty($parts['host']) ? $url : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? (preg_replace('/\s+/u', ' ', trim($value)) ?? '') : '';
    }
}
