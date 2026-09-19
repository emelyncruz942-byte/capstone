<?php

namespace Tests\Unit;

use App\Support\WebPushEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebPushEndpointTest extends TestCase
{
    private const HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        '*.notify.windows.com',
    ];

    #[DataProvider('allowedEndpoints')]
    public function test_it_allows_known_https_push_providers(string $endpoint): void
    {
        $this->assertTrue(WebPushEndpoint::isAllowed($endpoint, self::HOSTS));
    }

    public static function allowedEndpoints(): array
    {
        return [
            ['https://fcm.googleapis.com/fcm/send/subscription-id'],
            ['https://updates.push.services.mozilla.com/wpush/v2/subscription-id'],
            ['https://web.push.apple.com/QH123/subscription-id'],
            ['https://wns2-ams.notify.windows.com/w/?token=opaque'],
        ];
    }

    #[DataProvider('blockedEndpoints')]
    public function test_it_blocks_ssrf_and_provider_lookalikes(string $endpoint): void
    {
        $this->assertFalse(WebPushEndpoint::isAllowed($endpoint, self::HOSTS));
    }

    public static function blockedEndpoints(): array
    {
        return [
            ['http://fcm.googleapis.com/fcm/send/id'],
            ['https://127.0.0.1/push'],
            ['https://169.254.169.254/latest/meta-data'],
            ['https://fcm.googleapis.com.attacker.example/push'],
            ['https://attacker.example@fcm.googleapis.com/push'],
            ['https://fcm.googleapis.com:8443/push'],
            ['https://fcm.googleapis.com/push#fragment'],
            ['https://notify.windows.com/push'],
            ['https://attacker-notify.windows.com.example/push'],
        ];
    }
}
