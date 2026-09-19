<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedHostTest extends TestCase
{
    public function test_an_untrusted_host_header_is_rejected(): void
    {
        $this->withoutVite();

        $response = $this->get('http://attacker.example/');

        $response->assertStatus(400);
    }

    public function test_the_configured_application_host_is_allowed(): void
    {
        $this->withoutVite();

        $response = $this->get('http://localhost/');

        $response->assertOk();
    }

    public function test_the_legacy_laravel_cloud_host_redirects_to_the_canonical_domain(): void
    {
        $response = $this->get(
            'https://mathverse-production-luqbjt.laravel.cloud/reset-password?type=recovery'
        );

        $response->assertRedirect(
            'https://mathmetaverse.space/reset-password?type=recovery'
        );
        $response->assertStatus(308);
    }
}
