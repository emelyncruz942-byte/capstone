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

    // The login gateway is the site's only public landing page. Account,
    // dashboard, recovery, and report pages must not enter search results.
    $seoIndexable = request()->isMethod('GET') && request()->routeIs('login');
    $seoDefaultRobots = $seoIndexable
        ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
        : 'noindex, nofollow, noarchive';
    $seoRobots = trim($__env->yieldContent('robots', $seoDefaultRobots));
    $seoOgType = trim($__env->yieldContent('og_type', 'website'));
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
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
<script type="application/ld+json" nonce="{{ request()->attributes->get('csp_nonce') }}">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $seoSiteName,
    'alternateName' => 'Math MetaVerse',
    'url' => $seoBaseUrl . '/',
    'description' => $seoDescription,
    'inLanguage' => str_replace('_', '-', (string) config('seo.locale', 'en_PH')),
    'image' => $seoImageUrl,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
