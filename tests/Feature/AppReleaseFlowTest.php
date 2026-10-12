<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppReleaseFlowTest extends TestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';

    private const TEACHER = '22222222-2222-4222-8222-222222222222';

    private const ADMIN = '33333333-3333-4333-8333-333333333333';

    private const CURRENT_RELEASE = '44444444-4444-4444-8444-444444444444';

    private const HISTORICAL_RELEASE = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(SupabaseAuth::class);
        config([
            'mathverse.app_releases.bucket' => 'unity-releases',
            'mathverse.app_releases.max_bytes' => 1073741824,
            'mathverse.app_releases.signed_download_ttl' => 300,
        ]);
    }

    #[DataProvider('downloadRoles')]
    public function test_student_and_teacher_only_query_the_current_ready_release_for_each_platform(string $role): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            [
                'status' => 'ready',
                'is_active' => true,
                'order' => 'activated_at.desc',
                'limit' => 2,
            ],
        )->andReturn([
            'data' => [
                $this->release(),
                $this->release([
                    'id' => self::HISTORICAL_RELEASE,
                    'platform' => 'ios',
                    'artifact_kind' => 'ipa',
                    'storage_path' => 'releases/ios/'.self::HISTORICAL_RELEASE.'/mathverse-2.1.0.ipa',
                    'download_name' => 'MathVerse-2.1.0.ipa',
                    'mime_type' => 'application/x-itunes-ipa',
                ]),
            ],
            'error' => null,
            'status' => 200,
        ]);

        $response = $this->withSession(['supabase_user' => $this->user($role)])
            ->get('/game-download');

        $response->assertOk()
            ->assertViewIs('app-releases.latest')
            ->assertViewHas('releases', fn ($releases): bool =>
                count($releases) === 2
                && collect($releases)->every(fn ($release): bool => $release['is_active'] === true)
            )
            ->assertViewHas('releasesReady', true)
            ->assertSee('Download for Android')
            ->assertSee('Download for iOS')
            ->assertDontSee('Version 1.9.0');
    }

    public function test_admin_release_page_lists_current_and_historical_versions(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            ['order' => 'created_at.desc', 'limit' => 100],
        )->andReturn([
            'data' => [
                $this->release(),
                $this->release([
                    'id' => self::HISTORICAL_RELEASE,
                    'version' => '1.9.0',
                    'storage_path' => 'android/1.9.0/'.self::HISTORICAL_RELEASE.'/MathVerse-1.9.0.apk',
                    'download_name' => 'MathVerse-1.9.0.apk',
                    'is_active' => false,
                ]),
            ],
            'error' => null,
            'status' => 200,
        ]);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases')
            ->assertOk()
            ->assertViewIs('admin.app-releases.index')
            ->assertViewHas('releases', fn ($releases): bool => count($releases) === 2)
            ->assertSee('Version 2.1.0')
            ->assertSee('Version 1.9.0')
            ->assertSee('Make current');
    }

    public function test_admin_can_retry_or_discard_an_unfinished_upload(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn([
            'data' => [$this->release([
                'status' => 'uploading',
                'is_active' => false,
                'size_bytes' => null,
            ])],
            'error' => null,
            'status' => 200,
        ]);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases')
            ->assertOk()
            ->assertSee('Finish publishing')
            ->assertSee('Discard upload');
    }

    #[DataProvider('unsupportedBuilds')]
    public function test_upload_initiation_rejects_unsupported_mobile_artifacts(string $fileName): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldNotReceive('adminRpcResult');
        $database->shouldNotReceive('createStorageSignedUpload');

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/uploads', [
                'version' => '2.2.0',
                'file_name' => $fileName,
                'file_size' => 75000000,
                'release_notes' => 'Mobile build',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_name')
            ->assertJsonPath('message', 'Upload an Android APK or an iOS IPA build.');
    }

    #[DataProvider('supportedBuilds')]
    public function test_admin_can_initiate_android_and_ios_uploads(
        string $fileName,
        string $platform,
        string $artifactKind,
        string $mimeType,
    ): void {
        $release = $this->release([
            'platform' => $platform,
            'artifact_kind' => $artifactKind,
            'storage_path' => $platform.'/2.2.0/'.self::CURRENT_RELEASE.'/'.$fileName,
            'download_name' => $fileName,
            'version' => '2.2.0',
            'status' => 'uploading',
            'is_active' => false,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->withArgs(
            fn ($function, $arguments): bool => $function === 'begin_unity_app_release'
                && $arguments === [
                    'p_admin_id' => self::ADMIN,
                    'p_version' => '2.2.0',
                    'p_platform' => $platform,
                    'p_artifact_kind' => $artifactKind,
                    'p_original_filename' => $fileName,
                    'p_expected_size_bytes' => 75000000,
                    'p_release_notes' => 'Faster quiz loading',
                    'p_mime_type' => $mimeType,
                ]
        )->andReturn(['data' => [$release], 'error' => null, 'status' => 200]);
        $database->shouldReceive('createStorageSignedUpload')->once()->with(
            'unity-releases',
            $release['storage_path'],
        )->andReturn([
            'data' => [
                'token' => 'short-lived-upload-token',
                'endpoint' => 'https://project.storage.supabase.co/storage/v1/upload/resumable',
                'bucket' => 'unity-releases',
                'path' => $release['storage_path'],
            ],
            'error' => null,
            'status' => 200,
        ]);
        $database->shouldReceive('audit')->once()->withArgs(
            fn ($actor, $action, $type, $id, $metadata): bool => $actor['id'] === self::ADMIN
                && $action === 'unity_release.upload_started'
                && $type === 'unity_app_release'
                && $id === self::CURRENT_RELEASE
                && $metadata['platform'] === $platform
                && $metadata['size_bytes'] === 75000000
        )->andReturn(true);

        $response = $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/uploads', [
                'version' => 'v2.2.0',
                'file_name' => $fileName,
                'file_size' => 75000000,
                'release_notes' => ' Faster quiz loading ',
            ]);

        $response->assertCreated()
            ->assertJsonPath('release.id', self::CURRENT_RELEASE)
            ->assertJsonPath('release.version', '2.2.0')
            ->assertJsonPath('upload.token', 'short-lived-upload-token')
            ->assertJsonPath('upload.bucket', 'unity-releases')
            ->assertJsonPath('upload.content_type', $mimeType);

        $response->assertJsonMissing(['service_key' => 'service-role-key']);
        $this->assertStringNotContainsString('service-role-key', $response->getContent());
    }

    public function test_admin_can_complete_and_publish_a_verified_upload(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'expected_size_bytes' => 75000000,
            'size_bytes' => null,
        ]);
        $published = $this->release([
            'status' => 'ready',
            'is_active' => true,
            'size_bytes' => 75000000,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            ['id' => self::CURRENT_RELEASE, 'status' => 'uploading'],
        )->andReturn(['data' => [$pending], 'error' => null, 'status' => 200]);
        $database->shouldReceive('storageObjectInfo')->once()->with(
            'unity-releases',
            $pending['storage_path'],
        )->andReturn([
            'data' => ['size' => '75000000'],
            'error' => null,
            'status' => 200,
        ]);
        $database->shouldReceive('adminRpcResult')->once()->with(
            'finalize_unity_app_release',
            [
                'p_release_id' => self::CURRENT_RELEASE,
                'p_admin_id' => self::ADMIN,
                'p_actual_size_bytes' => 75000000,
                'p_sha256' => null,
            ],
        )->andReturn(['data' => [$published], 'error' => null, 'status' => 200]);
        $database->shouldReceive('audit')->once()->withArgs(
            fn ($actor, $action, $type, $id, $metadata): bool => $actor['id'] === self::ADMIN
                && $action === 'unity_release.published'
                && $type === 'unity_app_release'
                && $id === self::CURRENT_RELEASE
                && $metadata['size_bytes'] === 75000000
        )->andReturn(true);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::CURRENT_RELEASE.'/complete')
            ->assertOk()
            ->assertJsonPath('message', 'The new game version is now available.')
            ->assertJsonPath('release.id', self::CURRENT_RELEASE)
            ->assertJsonPath('release.is_active', true);
    }

    public function test_admin_can_retry_publishing_from_the_version_history(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'expected_size_bytes' => 75000000,
            'size_bytes' => null,
        ]);
        $published = $this->release();
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn([
            'data' => [$pending], 'error' => null, 'status' => 200,
        ]);
        $database->shouldReceive('storageObjectInfo')->once()->andReturn([
            'data' => ['size' => '75000000'], 'error' => null, 'status' => 200,
        ]);
        $database->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [$published], 'error' => null, 'status' => 200,
        ]);
        $database->shouldReceive('audit')->once()->andReturn(true);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->post('/admin/game-releases/'.self::CURRENT_RELEASE.'/complete')
            ->assertRedirect('/admin/game-releases')
            ->assertSessionHas('success', 'The new game version is now available.');
    }

    public function test_student_cannot_download_an_inactive_historical_release(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            [
                'id' => self::HISTORICAL_RELEASE,
                'status' => 'ready',
                'is_active' => true,
            ],
        )->andReturn(['data' => [], 'error' => null, 'status' => 200]);
        $database->shouldNotReceive('createStorageSignedDownload');

        $this->withSession(['supabase_user' => $this->user('student')])
            ->get('/game-download/'.self::HISTORICAL_RELEASE)
            ->assertRedirect('/game-download')
            ->assertSessionHas('error');
    }

    public function test_admin_can_download_an_inactive_historical_release(): void
    {
        $historical = $this->release([
            'id' => self::HISTORICAL_RELEASE,
            'version' => '1.9.0',
            'storage_path' => 'android/1.9.0/'.self::HISTORICAL_RELEASE.'/MathVerse-1.9.0.apk',
            'download_name' => 'MathVerse-1.9.0.apk',
            'is_active' => false,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            ['id' => self::HISTORICAL_RELEASE, 'status' => 'ready'],
        )->andReturn(['data' => [$historical], 'error' => null, 'status' => 200]);
        $database->shouldReceive('createStorageSignedDownload')->once()->with(
            'unity-releases',
            $historical['storage_path'],
            'MathVerse-1.9.0.apk',
            300,
        )->andReturn([
            'url' => 'https://project.supabase.co/storage/v1/object/sign/unity-releases/history.apk?token=signed',
            'error' => null,
            'status' => 200,
        ]);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases/'.self::HISTORICAL_RELEASE.'/download')
            ->assertRedirect('https://project.supabase.co/storage/v1/object/sign/unity-releases/history.apk?token=signed')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /** @return array<string, array{string}> */
    public static function downloadRoles(): array
    {
        return [
            'student' => ['student'],
            'teacher' => ['teacher'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unsupportedBuilds(): array
    {
        return [
            'Android App Bundle is not directly installable' => ['MathVerse.aab'],
            'desktop archive is outside the mobile-only scope' => ['MathVerse.zip'],
            'renamed executable is not accepted' => ['MathVerse.exe'],
        ];
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function supportedBuilds(): array
    {
        return [
            'Android APK' => [
                'MathVerse-2.2.0.apk',
                'android',
                'apk',
                'application/vnd.android.package-archive',
            ],
            'iOS IPA' => [
                'MathVerse-2.2.0.ipa',
                'ios',
                'ipa',
                'application/x-itunes-ipa',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function user(string $role): array
    {
        $ids = [
            'student' => self::STUDENT,
            'teacher' => self::TEACHER,
            'admin' => self::ADMIN,
        ];

        return [
            'id' => $ids[$role],
            'role' => $role,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $role.'@example.test',
        ];
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function release(array $overrides = []): array
    {
        return array_replace([
            'id' => self::CURRENT_RELEASE,
            'version' => '2.1.0',
            'platform' => 'android',
            'artifact_kind' => 'apk',
            'storage_bucket' => 'unity-releases',
            'storage_path' => 'android/2.1.0/'.self::CURRENT_RELEASE.'/MathVerse-2.1.0.apk',
            'download_name' => 'MathVerse-2.1.0.apk',
            'original_filename' => 'MathVerse-2.1.0.apk',
            'mime_type' => 'application/vnd.android.package-archive',
            'expected_size_bytes' => 75000000,
            'size_bytes' => 75000000,
            'release_notes' => 'Faster quiz loading',
            'status' => 'ready',
            'is_active' => true,
            'created_at' => '2026-10-11T12:00:00+00:00',
            'activated_at' => '2026-10-11T12:05:00+00:00',
        ], $overrides);
    }
}
