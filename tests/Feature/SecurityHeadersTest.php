<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_public_html_responses_receive_browser_security_headers(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $response->assertHeader('Origin-Agent-Cluster', '?1');
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("frame-src 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("script-src-attr 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/", $policy);

        $body = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $body);
        $this->assertMatchesRegularExpression('/<script\b[^>]*\bnonce="[^"]+"/i', $body);
    }

    public function test_password_reset_page_never_sends_a_referrer(): void
    {
        $this->withoutVite();

        $response = $this->get('/reset-password');

        $response->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_authenticated_json_responses_cannot_be_stored(): void
    {
        Route::middleware('web')->get('/_security-json-response', fn () => response()->json([
            'private' => true,
        ]));

        $response = $this->withSession([
            'supabase_user' => ['id' => 'user-id', 'role' => 'student'],
        ])->getJson('/_security-json-response');

        $response->assertOk();
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_api_preflight_allows_only_the_configured_exact_origin(): void
    {
        config(['cors.allowed_origins' => ['https://mathmetaverse.space']]);

        $allowed = $this->withHeaders([
            'Origin' => 'https://mathmetaverse.space',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-mathverse-signature',
        ])->options('/api/operations/monitor');

        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://mathmetaverse.space');
        $this->assertNotSame('*', $allowed->headers->get('Access-Control-Allow-Origin'));

        $this->flushHeaders();
        $rejected = $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/operations/monitor');

        // A single configured origin is emitted as a cacheable constant. The
        // attacker's browser sees a mismatch and denies access.
        $rejected->assertHeader('Access-Control-Allow-Origin', 'https://mathmetaverse.space');
        $this->assertNotSame(
            'https://attacker.example',
            $rejected->headers->get('Access-Control-Allow-Origin')
        );
    }
}
