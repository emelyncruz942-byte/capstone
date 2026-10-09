<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    public function test_public_gateway_has_canonical_search_and_social_metadata(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<title>MathVerse | Interactive Mathematics Learning Platform</title>', false)
            ->assertSee('<meta name="description" content="MathVerse is an online math learning platform for students and teachers in the Philippines with VR quiz bees, classroom quizzes, games, and progress tracking.">', false)
            ->assertSee('<meta name="keywords" content="VR math quiz bee, interactive mathematics learning platform, online math quiz for students, classroom quiz platform for teachers, virtual reality math game, math practice games, student progress tracking, online math learning platform Philippines">', false)
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
            ->assertSee('<link rel="canonical" href="https://mathmetaverse.space/">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:image" content="https://mathmetaverse.space/og-image-1200x630.jpg">', false)
            ->assertSee('<meta property="og:image:type" content="image/jpeg">', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="630">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('application/ld+json', false);

        $schema = $this->schemaFrom($response->getContent());
        $this->assertSame('https://schema.org', $schema['@context'] ?? null);
        $this->assertIsArray($schema['@graph'] ?? null);
        $this->assertSame(
            ['WebSite', 'WebPage', 'WebApplication'],
            array_column($schema['@graph'], '@type')
        );
        $this->assertSame(
            'https://mathmetaverse.space/#application',
            $schema['@graph'][1]['mainEntity']['@id'] ?? null
        );
        $this->assertStringContainsString(
            'VR math quiz bee',
            $schema['@graph'][0]['keywords'] ?? ''
        );
    }

    public function test_vr_math_quiz_bee_page_is_indexable_and_has_useful_public_content(): void
    {
        $response = $this->get('/vr-math-quiz-bee');

        $response->assertOk()
            ->assertSee('<title>MathVerse | VR Math Quiz Bee for Students and Teachers</title>', false)
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
            ->assertSee('<link rel="canonical" href="https://mathmetaverse.space/vr-math-quiz-bee">', false)
            ->assertSee('VR Math <span class="text-cyan-400">Quiz Bee</span> and Classroom Learning', false)
            ->assertSee('online math learning platform for students and teachers in the Philippines', false)
            ->assertSee('Do students need a VR headset to use MathVerse?', false)
            ->assertSee('href="'.route('login').'"', false);

        $schema = $this->schemaFrom($response->getContent());
        $this->assertSame(
            ['WebSite', 'WebPage', 'WebApplication'],
            array_column($schema['@graph'], '@type')
        );
        $this->assertContains(
            'Virtual-reality and standard-screen math quiz modes',
            $schema['@graph'][2]['featureList'] ?? []
        );
    }

    public function test_account_recovery_page_is_not_indexable_and_has_a_queryless_canonical(): void
    {
        $response = $this->get('/reset-password?token=secret');

        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertSee('<link rel="canonical" href="https://mathmetaverse.space/reset-password">', false)
            ->assertDontSee('secret', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_public_legal_pages_are_indexable_and_have_page_schema(): void
    {
        foreach (['/privacy-policy', '/terms-and-conditions'] as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
                ->assertSee('<link rel="canonical" href="https://mathmetaverse.space'.$path.'">', false)
                ->assertDontSee('<meta name="keywords"', false);

            $schema = $this->schemaFrom($response->getContent());
            $this->assertSame('https://schema.org', $schema['@context'] ?? null);
            $this->assertSame(['WebSite', 'WebPage'], array_column($schema['@graph'], '@type'));
        }
    }

    public function test_robots_file_exposes_the_sitemap_and_excludes_private_areas(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertIsString($robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('Disallow: /teacher/', $robots);
        $this->assertStringContainsString('Disallow: /student/', $robots);
        $this->assertStringNotContainsString('Disallow: /up', $robots);
        $this->assertStringContainsString('Sitemap: https://mathmetaverse.space/sitemap.xml', $robots);
        $this->assertFileExists(public_path('sitemap.xml'));

        $sitemap = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($sitemap);
        $this->assertStringContainsString('<loc>https://mathmetaverse.space/privacy-policy</loc>', $sitemap);
        $this->assertStringContainsString('<loc>https://mathmetaverse.space/terms-and-conditions</loc>', $sitemap);
        $this->assertStringContainsString('<loc>https://mathmetaverse.space/vr-math-quiz-bee</loc>', $sitemap);
    }

    public function test_health_endpoint_is_reachable_but_cannot_be_indexed_or_cached(): void
    {
        $response = $this->get('/up');

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_missing_public_page_uses_branded_non_indexable_404(): void
    {
        $response = $this->get('/page-that-does-not-exist');

        $response->assertNotFound()
            ->assertSee('Page <span class="text-cyan-400">Not Found</span>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('privacy-policy').'"', false)
            ->assertSee('href="'.route('terms-and-conditions').'"', false)
            ->assertDontSee('application/ld+json', false);
    }

    /** @return array<string, mixed> */
    private function schemaFrom(string|false $html): array
    {
        $this->assertIsString($html);
        $matched = preg_match(
            '#<script\s+type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html,
            $matches
        );

        $this->assertSame(1, $matched, 'Expected one JSON-LD script in the response.');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
