<?php

declare(strict_types=1);

// Make local and CI analysis deterministic before Larastan boots Laravel.
$environment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    // A deterministic, non-secret key used only while static analysis boots Laravel.
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)),
    'CACHE_STORE' => 'array',
    'CACHE_LIMITER' => 'array',
    'SESSION_DRIVER' => 'array',
];

foreach ($environment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/vendor/phpstan/phpstan/phpstan';
