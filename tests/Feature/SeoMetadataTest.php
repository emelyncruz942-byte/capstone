<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    public function test_public_gateway_has_canonical_search_and_social_metadata(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<title>MathVerse | Academic Portal</title>', false)
            ->assertSee('<meta name="description" content="Learn mathematics through immersive VR quiz bees, classroom challenges, practice activities, and progress tracking in MathVerse.">', false)
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
                ->assertSee('<link rel="canonical" href="https://mathmetaverse.space'.$path.'">', false);

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
