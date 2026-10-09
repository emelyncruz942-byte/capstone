<?php

return [
    'site_name' => env('SEO_SITE_NAME', 'MathVerse'),

    'description' => env(
        'SEO_DESCRIPTION',
        'MathVerse is an online math learning platform for students and teachers in the Philippines with VR quiz bees, classroom quizzes, games, and progress tracking.'
    ),

    'keywords' => [
        'VR math quiz bee',
        'interactive mathematics learning platform',
        'online math quiz for students',
        'classroom quiz platform for teachers',
        'virtual reality math game',
        'math practice games',
        'student progress tracking',
        'online math learning platform Philippines',
    ],

    // Keep this path public and absolute-from-root so social crawlers can fetch it.
    'image' => env('SEO_IMAGE', '/og-image-1200x630.jpg'),
    'image_alt' => env('SEO_IMAGE_ALT', 'MathVerse interactive mathematics learning platform'),
    'image_width' => 1200,
    'image_height' => 630,
    'image_type' => 'image/jpeg',

    'locale' => env('SEO_LOCALE', 'en_PH'),
    'twitter_card' => 'summary_large_image',
];
