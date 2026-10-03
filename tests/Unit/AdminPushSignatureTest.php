<?php

namespace Tests\Unit;

use App\Services\AdminPushService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPushSignatureTest extends TestCase
{
    public function test_push_requests_use_a_timestamped_body_signature_and_one_time_nonce(): void
    {
        $secret = str_repeat('s', 40);
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'public-anon-key',
            'services.web_push.public_key' => 'public-vapid-key',
            'services.web_push.function_url' => '',
            'services.web_push.auth_secret' => $secret,
        ]);
        Http::fake([
            'https://project.supabase.co/functions/v1/send-admin-push' => Http::response([
                'sent' => 1,
                'failed' => 0,
            ]),
        ]);

        $this->assertTrue(app(AdminPushService::class)->send(
            'Security notice',
            'A signed push fixture.',
            '/admin/incidents',
            'security-fixture',
        ));

        Http::assertSent(function (Request $request) use ($secret): bool {
            $timestamp = $request->header('X-MathVerse-Timestamp')[0] ?? '';
            $nonce = $request->header('X-MathVerse-Nonce')[0] ?? '';
            $signature = $request->header('X-MathVerse-Signature')[0] ?? '';
            $body = $request->body();
            $expected = hash_hmac(
                'sha256',
                'v2:send-admin-push:POST:/send-admin-push:'
                    .$timestamp.':'.$nonce.':'.hash('sha256', $body),
                $secret,
            );

            return preg_match('/^[0-9]{10}$/', $timestamp) === 1
                && abs(time() - (int) $timestamp) <= 5
                && preg_match('/^[A-Za-z0-9_-]{32,128}$/', $nonce) === 1
                && hash_equals($expected, $signature)
                && ! $request->hasHeader('X-MathVerse-Push-Secret')
                && ! $request->hasHeader('Authorization')
                && $request->hasHeader('apikey', 'public-anon-key');
        });
    }
}
