<?php

namespace App\Support;

final class ClassCustomization
{
    public const COLORS = [
        '#f59e0b', '#06b6d4', '#8b5cf6', '#22c55e', '#ec4899', '#3b82f6',
    ];

    public const ICONS = ['chalkboard', 'calculator', 'rocket', 'atom', 'shapes', 'gamepad'];

    public const PATTERNS = ['grid', 'stars', 'circuit', 'waves', 'plain'];

    public static function normalize(array $value, string $defaultColor = '#f59e0b'): array
    {
        if (!in_array($defaultColor, self::COLORS, true)) {
            $defaultColor = self::COLORS[0];
        }

        return array_merge($value, [
            'theme_color' => in_array($value['theme_color'] ?? null, self::COLORS, true)
                ? $value['theme_color']
                : $defaultColor,
            'icon' => in_array($value['icon'] ?? null, self::ICONS, true)
                ? $value['icon']
                : self::ICONS[0],
            'banner_pattern' => in_array($value['banner_pattern'] ?? null, self::PATTERNS, true)
                ? $value['banner_pattern']
                : self::PATTERNS[0],
        ]);
    }
}
