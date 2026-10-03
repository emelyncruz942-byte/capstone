<?php

$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://mathmetaverse.space')),
)));

return [
    // Only the intentionally machine-facing endpoints are under /api.
    // Native Unity clients are not governed by browser CORS.
    'paths' => ['api/*'],
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-MathVerse-Timestamp',
        'X-MathVerse-Nonce',
        'X-MathVerse-Signature',
    ],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
