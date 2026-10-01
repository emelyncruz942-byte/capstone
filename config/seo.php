<?php

return [
    'site_name' => env('SEO_SITE_NAME', 'MathVerse'),

    'description' => env(
        'SEO_DESCRIPTION',
        'MathVerse is an interactive mathematics learning platform with virtual-reality quiz bees, classrooms, practice activities, and progress insights for students and teachers.'
    ),

    // Keep this path public and absolute-from-root so social crawlers can fetch it.
    'image' => env('SEO_IMAGE', '/og-image.png'),
    'image_alt' => env('SEO_IMAGE_ALT', 'MathVerse interactive mathematics learning platform'),
    'image_width' => 1733,
    'image_height' => 908,
    'image_type' => 'image/png',

    'locale' => env('SEO_LOCALE', 'en_PH'),
    'twitter_card' => 'summary_large_image',
];
