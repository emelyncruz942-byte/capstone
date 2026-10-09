@php
    $seoSiteName = trim((string) config('seo.site_name', 'MathVerse'));
    $seoPageTitle = trim($__env->yieldContent('title', 'Academic Portal'));
    $seoTitle = $seoPageTitle === '' || strcasecmp($seoPageTitle, $seoSiteName) === 0
        ? $seoSiteName
        : $seoSiteName . ' | ' . $seoPageTitle;
    $seoDescription = trim((string) preg_replace(
        '/\s+/u',
        ' ',
        $__env->yieldContent('description', (string) config('seo.description', ''))
    ));
    $seoKeywords = array_values(array_filter(
        array_map('trim', (array) config('seo.keywords', [])),
        static fn (string $keyword): bool => $keyword !== ''
    ));

    $seoBaseUrl = rtrim((string) config('app.canonical_url', config('app.url')), '/');
    $seoPath = request()->getPathInfo();
    $seoCanonicalOverride = trim($__env->yieldContent('canonical'));
    $seoCanonicalUrl = $seoCanonicalOverride !== ''
        ? $seoCanonicalOverride
        : $seoBaseUrl . ($seoPath === '/' ? '/' : $seoPath);

    $seoImagePath = trim((string) config('seo.image', '/og-image.png'));
    $seoImageUrl = preg_match('#^https?://#i', $seoImagePath) === 1
        ? $seoImagePath
        : $seoBaseUrl . '/' . ltrim($seoImagePath, '/');

    // Only intentional public pages may enter search results. Account,
    // dashboard, recovery, report, error, and service-health pages stay out.
    $seoIndexable = request()->isMethod('GET')
        && request()->routeIs(['login', 'vr-math-quiz-bee', 'privacy-policy', 'terms-and-conditions']);
    $seoKeywordMetaEnabled = request()->routeIs(['login', 'vr-math-quiz-bee']);
    $seoDefaultRobots = $seoIndexable
        ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
        : 'noindex, nofollow, noarchive';
    $seoRobots = trim($__env->yieldContent('robots', $seoDefaultRobots));
    $seoOgType = trim($__env->yieldContent('og_type', 'website'));

    // Build Schema.org keys without literal Blade-style @ directives. Keeping
    // the data in one graph makes each public page describe itself while
    // retaining a stable relationship to the website and web application.
    $schemaKey = static fn (string $name): string => chr(64) . $name;
    $seoWebsiteId = $seoBaseUrl . '/#website';
    $seoApplicationId = $seoBaseUrl . '/#application';
    $seoWebPageId = $seoCanonicalUrl . '#webpage';
    $seoSchemaGraph = [
        [
            $schemaKey('type') => 'WebSite',
            $schemaKey('id') => $seoWebsiteId,
            'name' => $seoSiteName,
            'alternateName' => 'Math MetaVerse',
            'url' => $seoBaseUrl . '/',
            'description' => (string) config('seo.description', $seoDescription),
            'keywords' => implode(', ', $seoKeywords),
            'inLanguage' => str_replace('_', '-', (string) config('seo.locale', 'en_PH')),
        ],
        [
            $schemaKey('type') => 'WebPage',
            $schemaKey('id') => $seoWebPageId,
            'url' => $seoCanonicalUrl,
            'name' => $seoTitle,
            'description' => $seoDescription,
            'inLanguage' => str_replace('_', '-', (string) config('seo.locale', 'en_PH')),
            'isPartOf' => [$schemaKey('id') => $seoWebsiteId],
            'primaryImageOfPage' => [
                $schemaKey('type') => 'ImageObject',
                'url' => $seoImageUrl,
                'width' => (int) config('seo.image_width', 1200),
                'height' => (int) config('seo.image_height', 630),
            ],
        ],
    ];

    if (request()->routeIs(['login', 'vr-math-quiz-bee'])) {
        $seoSchemaGraph[] = [
            $schemaKey('type') => 'WebApplication',
            $schemaKey('id') => $seoApplicationId,
            'name' => $seoSiteName,
            'url' => $seoBaseUrl . '/',
            'description' => (string) config('seo.description', $seoDescription),
            'applicationCategory' => 'EducationalApplication',
            'operatingSystem' => 'Any modern web browser',
            'featureList' => [
                'Virtual-reality and standard-screen math quiz modes',
                'Teacher-managed classrooms and quizzes',
                'Student math practice games and activities',
                'Quiz scoring and learning progress insights',
            ],
            'isPartOf' => [$schemaKey('id') => $seoWebsiteId],
        ];
        $seoSchemaGraph[1]['mainEntity'] = [$schemaKey('id') => $seoApplicationId];
    }

    $seoSchema = [
        $schemaKey('context') => 'https://schema.org',
        $schemaKey('graph') => $seoSchemaGraph,
    ];
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
@if($seoKeywordMetaEnabled && $seoKeywords !== [])
<meta name="keywords" content="{{ implode(', ', $seoKeywords) }}">
@endif
<meta name="robots" content="{{ $seoRobots }}">
<meta name="googlebot" content="{{ $seoRobots }}">
<link rel="canonical" href="{{ $seoCanonicalUrl }}">

<meta property="og:site_name" content="{{ $seoSiteName }}">
<meta property="og:locale" content="{{ config('seo.locale', 'en_PH') }}">
<meta property="og:type" content="{{ $seoOgType }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $seoCanonicalUrl }}">
<meta property="og:image" content="{{ $seoImageUrl }}">
<meta property="og:image:secure_url" content="{{ $seoImageUrl }}">
<meta property="og:image:type" content="{{ config('seo.image_type', 'image/png') }}">
<meta property="og:image:width" content="{{ config('seo.image_width', 1200) }}">
<meta property="og:image:height" content="{{ config('seo.image_height', 630) }}">
<meta property="og:image:alt" content="{{ config('seo.image_alt', $seoSiteName) }}">

<meta name="twitter:card" content="{{ config('seo.twitter_card', 'summary_large_image') }}">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImageUrl }}">
<meta name="twitter:image:alt" content="{{ config('seo.image_alt', $seoSiteName) }}">

@if($seoIndexable)
<script type="application/ld+json" nonce="{{ request()->attributes->get('csp_nonce') }}">{!! json_encode($seoSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
