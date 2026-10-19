<?php

namespace Tests\Feature;

use App\Services\SupabaseService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseStorageReleaseTest extends TestCase
{
    private const PROJECT_URL = 'https://project-ref.supabase.co';

    private const SERVICE_KEY = 'private-service-role-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.supabase.url', self::PROJECT_URL);
        config()->set('services.supabase.anon_key', 'public-anon-key');
        config()->set('services.supabase.service_key', self::SERVICE_KEY);
    }

    public function test_signed_upload_uses_the_private_storage_api_and_returns_only_the_upload_token_and_direct_endpoint(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/upload/sign/*' => Http::response([
                'url' => '/object/upload/sign/unity-releases/releases/release-id/MathVerse-2.4.0.apk?token=signed-upload-token',
            ], 200),
        ]);

        $result = $this->service()->createStorageSignedUpload(
            'unity-releases',
            'releases/release-id/MathVerse-2.4.0.apk',
        );

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['error']);
        $this->assertSame('signed-upload-token', $result['data']['token']);
        $this->assertSame(
            'https://project-ref.storage.supabase.co/storage/v1/upload/resumable/sign',
            $result['data']['endpoint'],
        );
        $this->assertSame('unity-releases', $result['data']['bucket']);
        $this->assertSame(
            'releases/release-id/MathVerse-2.4.0.apk',
            $result['data']['path'],
        );
        $this->assertArrayNotHasKey('service_key', $result['data']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::PROJECT_URL
                    . '/storage/v1/object/upload/sign/unity-releases/releases/release-id/MathVerse-2.4.0.apk'
                && $request->hasHeader('apikey', self::SERVICE_KEY)
                && $request->hasHeader('Authorization', 'Bearer ' . self::SERVICE_KEY)
                && $request->hasHeader('x-upsert', 'false')
                && $request->data() === [];
        });
    }

    public function test_signed_upload_uses_the_configured_storage_host_for_non_supabase_deployments(): void
    {
        config()->set('services.supabase.url', 'http://storage.local.test:54321');
        Http::fake([
            'storage.local.test:54321/*' => Http::response([
                'url' => '/object/upload/sign/unity-releases/releases/release-id/app.ipa?token=local-token',
            ], 200),
        ]);

        $result = $this->service()->createStorageSignedUpload(
            'unity-releases',
            'releases/release-id/app.ipa',
        );

        $this->assertSame(
            'http://storage.local.test:54321/storage/v1/upload/resumable/sign',
            $result['data']['endpoint'],
        );
    }

    public function test_storage_object_info_uses_the_service_role_and_exact_encoded_path(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/info/*' => Http::response([
                'size' => 73400320,
                'mimetype' => 'application/vnd.android.package-archive',
            ], 200),
        ]);

        $result = $this->service()->storageObjectInfo(
            'unity-releases',
            'releases/release-id/MathVerse_2.4.0.apk',
        );

        $this->assertNull($result['error']);
        $this->assertSame(73400320, $result['data']['size']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::PROJECT_URL
                    . '/storage/v1/object/info/unity-releases/releases/release-id/MathVerse_2.4.0.apk'
                && $request->hasHeader('apikey', self::SERVICE_KEY)
                && $request->hasHeader('Authorization', 'Bearer ' . self::SERVICE_KEY);
        });
    }

    public function test_storage_object_delete_uses_the_service_role_and_exact_encoded_path(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/*' => Http::response([], 200),
        ]);

        $deleted = $this->service()->deleteStorageObject(
            'unity-releases',
            'releases/release-id/MathVerse_2.4.0.apk',
        );

        $this->assertTrue($deleted);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === self::PROJECT_URL
                    . '/storage/v1/object/unity-releases/releases/release-id/MathVerse_2.4.0.apk'
                && $request->hasHeader('apikey', self::SERVICE_KEY)
                && $request->hasHeader('Authorization', 'Bearer ' . self::SERVICE_KEY);
        });
    }

    public function test_storage_object_delete_treats_an_already_missing_object_as_clean(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/*' => Http::response([
                'message' => 'Object not found',
            ], 404),
        ]);

        $this->assertTrue($this->service()->deleteStorageObject(
            'unity-releases',
            'releases/release-id/MathVerse_2.4.0.apk',
        ));
    }

    public function test_storage_object_delete_rejects_traversal_before_http(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Supabase storage location.');

        try {
            $this->service()->deleteStorageObject(
                'unity-releases',
                'releases/../private/MathVerse.apk',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_signed_download_uses_the_requested_ttl_and_safe_download_filename(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/sign/*' => Http::response([
                'signedURL' => '/object/sign/unity-releases/releases/release-id/app.ipa?token=download-token',
            ], 200),
        ]);

        $result = $this->service()->createStorageSignedDownload(
            'unity-releases',
            'releases/release-id/app.ipa',
            'MathVerse-iOS-2.4.0.ipa',
            480,
        );

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(
            self::PROJECT_URL
                . '/storage/v1/object/sign/unity-releases/releases/release-id/app.ipa'
                . '?token=download-token&download=MathVerse-iOS-2.4.0.ipa',
            $result['url'],
        );

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::PROJECT_URL
                    . '/storage/v1/object/sign/unity-releases/releases/release-id/app.ipa'
                && $request->hasHeader('apikey', self::SERVICE_KEY)
                && $request->hasHeader('Authorization', 'Bearer ' . self::SERVICE_KEY)
                && $request->data() === ['expiresIn' => 480];
        });
    }

    public function test_signed_download_ttl_is_limited_to_a_short_lived_range(): void
    {
        Http::fake([
            'project-ref.supabase.co/storage/v1/object/sign/*' => Http::response([
                'signedURL' => '/object/sign/unity-releases/releases/release-id/app.apk?token=download-token',
            ], 200),
        ]);

        $this->service()->createStorageSignedDownload(
            'unity-releases',
            'releases/release-id/app.apk',
            'MathVerse.apk',
            86400,
        );

        Http::assertSent(fn (Request $request): bool => $request->data() === ['expiresIn' => 900]);
    }

    #[DataProvider('invalidStorageLocations')]
    public function test_storage_requests_reject_invalid_or_traversal_locations_before_http(
        string $bucket,
        string $path,
    ): void {
        Http::fake();

        try {
            $this->service()->createStorageSignedUpload($bucket, $path);
            $this->fail('An invalid storage location was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid Supabase storage location.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidStorageLocations(): array
    {
        return [
            'parent traversal' => ['unity-releases', 'releases/../private/app.apk'],
            'current directory traversal' => ['unity-releases', 'releases/./app.apk'],
            'encoded traversal' => ['unity-releases', 'releases/%2e%2e/app.apk'],
            'backslash traversal' => ['unity-releases', 'releases\\..\\app.apk'],
            'duplicate path separator' => ['unity-releases', 'releases//app.apk'],
            'absolute object path' => ['unity-releases', '/releases/app.apk'],
            'unsafe bucket' => ['unity-releases/other', 'releases/app.apk'],
        ];
    }

    public function test_signed_download_rejects_a_header_or_query_injection_filename_before_http(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid storage download name.');

        try {
            $this->service()->createStorageSignedDownload(
                'unity-releases',
                'releases/release-id/app.apk',
                "MathVerse.apk\r\nContent-Type:text/html",
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    private function service(): SupabaseService
    {
        return new SupabaseService();
    }
}
