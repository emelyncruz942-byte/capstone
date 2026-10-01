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
            ->assertSee('<meta property="og:image" content="https://mathmetaverse.space/og-image.png">', false)
            ->assertSee('<meta property="og:image:width" content="1733">', false)
            ->assertSee('<meta property="og:image:height" content="908">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('https://schema.org', false);
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

    public function test_robots_file_exposes_the_sitemap_and_excludes_private_areas(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertIsString($robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('Disallow: /teacher/', $robots);
        $this->assertStringContainsString('Disallow: /student/', $robots);
        $this->assertStringContainsString('Sitemap: https://mathmetaverse.space/sitemap.xml', $robots);
        $this->assertFileExists(public_path('sitemap.xml'));
    }
}
