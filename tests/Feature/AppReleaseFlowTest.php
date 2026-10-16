<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\CloudflareR2Service;
use App\Services\SupabaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AppReleaseFlowTest extends TestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';

    private const TEACHER = '22222222-2222-4222-8222-222222222222';

    private const ADMIN = '33333333-3333-4333-8333-333333333333';

    private const RELEASE = '44444444-4444-4444-8444-444444444444';

    private const HISTORICAL = '55555555-5555-4555-8555-555555555555';

    private const PART_SIZE = 16777216;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(SupabaseAuth::class);
        config([
            'mathverse.app_releases.bucket' => 'unity-releases',
            'mathverse.app_releases.max_bytes' => 1073741824,
            'mathverse.app_releases.signed_download_ttl' => 300,
            'mathverse.app_releases.r2.upload_url_ttl' => 900,
            'mathverse.app_releases.r2.signed_download_ttl' => 300,
        ]);
    }

    #[DataProvider('downloadRoles')]
    public function test_student_and_teacher_see_only_current_ready_releases(string $role): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*',
            ['status' => 'ready', 'is_active' => true, 'order' => 'activated_at.desc', 'limit' => 2],
        )->andReturn(['data' => [$this->release(), $this->release([
            'id' => self::HISTORICAL,
            'platform' => 'ios',
            'artifact_kind' => 'ipa',
            'storage_path' => 'releases/ios/'.self::HISTORICAL.'/mathverse-2.1.0.ipa',
            'download_name' => 'MathVerse-2.1.0.ipa',
        ])], 'error' => null]);
        $this->mock(CloudflareR2Service::class);

        $this->withSession(['supabase_user' => $this->user($role)])
            ->get('/game-download')
            ->assertOk()
            ->assertViewHas('releases', fn (array $releases): bool => count($releases) === 2)
            ->assertSee('Download for Android')
            ->assertSee('Download for iOS');
    }

    public function test_admin_page_reports_when_r2_is_not_configured(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*,storage_provider,provider_upload_id,object_etag,multipart_part_size',
            ['order' => 'created_at.desc', 'limit' => 100],
        )->andReturn(['data' => [$this->release()], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->once()->andReturn(false);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases')
            ->assertOk()
            ->assertSee('Cloudflare R2 is not configured')
            ->assertSee('disabled', false);
    }

    public function test_admin_page_disables_uploads_until_the_r2_release_migration_is_applied(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*,storage_provider,provider_upload_id,object_etag,multipart_part_size',
            ['order' => 'created_at.desc', 'limit' => 100],
        )->andReturn([
            'data' => [],
            'error' => 'column unity_app_releases.storage_provider does not exist',
        ]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->never();

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases')
            ->assertOk()
            ->assertSee('Apply the release migrations')
            ->assertSee('disabled', false);
    }

    public function test_admin_page_only_offers_cleanup_retry_while_an_r2_cleanup_marker_remains(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases',
            '*,storage_provider,provider_upload_id,object_etag,multipart_part_size',
            ['order' => 'created_at.desc', 'limit' => 100],
        )->andReturn(['data' => [
            $this->release([
                'status' => 'failed',
                'is_active' => false,
                'provider_upload_id' => 'cleanup-pending-upload',
                'object_etag' => null,
            ]),
            $this->release([
                'id' => self::HISTORICAL,
                'status' => 'failed',
                'is_active' => false,
                'provider_upload_id' => null,
                'object_etag' => null,
            ]),
        ], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->once()->andReturn(true);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases')
            ->assertOk()
            ->assertSee('Retry storage cleanup', false)
            ->assertSee(route('admin.app-releases.fail', ['releaseId' => self::RELEASE]), false)
            ->assertDontSee(route('admin.app-releases.fail', ['releaseId' => self::HISTORICAL]), false);
    }

    #[DataProvider('unsupportedBuilds')]
    public function test_upload_initiation_rejects_unsupported_artifacts(string $fileName): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldNotReceive('adminRpcResult');
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->once()->andReturn(true);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/uploads', [
                'version' => '2.2.0', 'file_name' => $fileName, 'file_size' => 75000000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_name');
    }

    #[DataProvider('supportedBuilds')]
    public function test_admin_starts_private_r2_multipart_uploads(
        string $fileName,
        string $platform,
        string $artifactKind,
        string $mimeType,
    ): void {
        $pending = $this->release([
            'version' => '2.2.0',
            'platform' => $platform,
            'artifact_kind' => $artifactKind,
            'mime_type' => $mimeType,
            'storage_path' => 'releases/'.$platform.'/'.self::RELEASE.'/mathverse-2.2.0.'.$artifactKind,
            'status' => 'uploading',
            'is_active' => false,
            'size_bytes' => null,
            'expected_size_bytes' => 75000000,
            'object_etag' => null,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->with('begin_unity_app_release_v2', [
            'p_admin_id' => self::ADMIN,
            'p_version' => '2.2.0',
            'p_platform' => $platform,
            'p_artifact_kind' => $artifactKind,
            'p_original_filename' => $fileName,
            'p_expected_size_bytes' => 75000000,
            'p_release_notes' => 'Faster quiz loading',
            'p_mime_type' => $mimeType,
            'p_multipart_part_size' => self::PART_SIZE,
            'p_storage_bucket' => 'unity-releases',
        ])->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('attach_unity_app_release_upload_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_upload_id' => 'private-multipart-id',
        ])->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('audit')->once()->andReturn(true);

        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->once()->andReturn(true);
        $r2->shouldReceive('bucket')->once()->andReturn('unity-releases');
        $r2->shouldReceive('partSize')->once()->andReturn(self::PART_SIZE);
        $r2->shouldReceive('createMultipartUpload')->once()->withArgs(
            fn ($bucket, $path, $mime, $metadata): bool => $bucket === 'unity-releases'
                && $path === $pending['storage_path']
                && $mime === $mimeType
                && $metadata['release-id'] === self::RELEASE
                && $metadata['expected-size'] === '75000000'
        )->andReturn(['upload_id' => 'private-multipart-id']);

        $response = $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/uploads', [
                'version' => 'v2.2.0',
                'file_name' => $fileName,
                'file_size' => 75000000,
                'release_notes' => ' Faster quiz loading ',
            ]);

        $response->assertCreated()
            ->assertJsonPath('release.id', self::RELEASE)
            ->assertJsonPath('upload.part_size', self::PART_SIZE)
            ->assertJsonPath('upload.part_count', 5)
            ->assertJsonStructure(['upload' => ['sign_part_url']]);
        $this->assertStringNotContainsString('private-multipart-id', $response->getContent());
        $this->assertStringNotContainsString('secret', strtolower($response->getContent()));
    }

    public function test_admin_can_sign_only_an_expected_part_for_an_uploading_r2_release(): void
    {
        $pending = $this->release([
            'status' => 'uploading', 'is_active' => false, 'size_bytes' => null,
            'expected_size_bytes' => self::PART_SIZE * 2 + 1,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$pending], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('createSignedPartUpload')->once()->with(
            'unity-releases', $pending['storage_path'], 'private-multipart-id', 3, 900,
        )->andReturn(['url' => 'https://signed.r2.example/part-3', 'expires_at' => 12345]);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/parts', ['part_number' => 3])
            ->assertOk()
            ->assertJsonPath('url', 'https://signed.r2.example/part-3');
    }

    public function test_r2_completion_validates_every_part_and_head_before_publishing(): void
    {
        $expected = self::PART_SIZE * 2 + 1;
        $pending = $this->release([
            'status' => 'uploading', 'is_active' => false, 'size_bytes' => null,
            'expected_size_bytes' => $expected,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $published = $this->release(['expected_size_bytes' => $expected, 'size_bytes' => $expected]);
        $parts = [
            ['PartNumber' => 1, 'ETag' => 'p1', 'Size' => self::PART_SIZE],
            ['PartNumber' => 2, 'ETag' => 'p2', 'Size' => self::PART_SIZE],
            ['PartNumber' => 3, 'ETag' => 'p3', 'Size' => 1],
        ];
        $info = $this->objectInfo($pending, $expected, 'finished-etag');

        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('finalize_unity_app_release_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_actual_size_bytes' => $expected,
            'p_object_etag' => 'finished-etag',
            'p_sha256' => null,
        ])->andReturn(['data' => [$published], 'error' => null]);
        $database->shouldReceive('audit')->once()->andReturn(true);

        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('objectInfo')->once()->ordered()->andThrow(new RuntimeException('not completed'));
        $r2->shouldReceive('listUploadedParts')->once()->ordered()->andReturn($parts);
        $r2->shouldReceive('completeMultipartUpload')->once()->ordered()->with(
            'unity-releases', $pending['storage_path'], 'private-multipart-id', $parts,
        )->andReturn(['etag' => 'finished-etag']);
        $r2->shouldReceive('objectInfo')->once()->ordered()->andReturn($info);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/complete')
            ->assertOk()
            ->assertJsonPath('release.is_active', true);
    }

    public function test_r2_size_mismatch_never_finalizes_the_database_release(): void
    {
        $pending = $this->release(['status' => 'uploading', 'is_active' => false, 'size_bytes' => null]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [], 'error' => null]);
        $database->shouldNotReceive('adminRpcResult');
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('objectInfo')->once()->andReturn($this->objectInfo($pending, 74_999_999, 'wrong'));

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/complete')
            ->assertUnprocessable();
    }

    public function test_discarding_an_r2_upload_aborts_parts_deletes_an_orphan_and_clears_database_state(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $failed = $this->release([
            'status' => 'failed',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
            'object_etag' => null,
        ]);
        $cleaned = array_replace($failed, ['provider_upload_id' => null]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('fail_unity_app_release_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_failure_reason' => 'Administrator cancelled the upload.',
        ])->andReturn(['data' => [$failed], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('finish_unity_app_release_cleanup_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_upload_id' => 'private-multipart-id',
        ])->andReturn(['data' => [$cleaned], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('abortMultipartUpload')->once()->with(
            'unity-releases', $pending['storage_path'], 'private-multipart-id',
        );
        $r2->shouldReceive('deleteObject')->once()->with('unity-releases', $pending['storage_path']);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/fail', [
                'reason' => 'Administrator cancelled the upload.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'The interrupted upload was discarded.');
    }

    public function test_r2_cleanup_failure_is_visible_to_the_administrator(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $failed = $this->release([
            'status' => 'failed',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
            'object_etag' => null,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->andReturn(['data' => [$failed], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('abortMultipartUpload')->once();
        $r2->shouldReceive('deleteObject')->once()->andThrow(new RuntimeException('temporary R2 failure'));

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/fail', [
                'reason' => 'Administrator cancelled the upload.',
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'message',
                'The release is marked failed, but R2 storage cleanup did not finish. Use Retry storage cleanup to try again.',
            );
    }

    public function test_failed_r2_release_storage_cleanup_can_be_retried(): void
    {
        $failed = $this->release([
            'status' => 'failed',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
            'object_etag' => null,
        ]);
        $cleaned = array_replace($failed, ['provider_upload_id' => null]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [], 'error' => null]);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$failed], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('fail_unity_app_release_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_failure_reason' => 'Storage cleanup retried by an administrator.',
        ])->andReturn(['data' => [$failed], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('finish_unity_app_release_cleanup_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_upload_id' => 'private-multipart-id',
        ])->andReturn(['data' => [$cleaned], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('abortMultipartUpload')->once()->with(
            'unity-releases', $failed['storage_path'], 'private-multipart-id',
        );
        $r2->shouldReceive('deleteObject')->once()->with('unity-releases', $failed['storage_path']);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/fail', [
                'reason' => 'Storage cleanup retried by an administrator.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'The interrupted upload was discarded.');
    }

    public function test_completion_cleans_a_completed_orphan_when_a_concurrent_discard_wins(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $failed = $this->release([
            'status' => 'failed',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
            'object_etag' => null,
        ]);
        $cleaned = array_replace($failed, ['provider_upload_id' => null]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->ordered()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->ordered()->with('finalize_unity_app_release_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_actual_size_bytes' => 75000000,
            'p_object_etag' => 'completed-etag',
            'p_sha256' => null,
        ])->andReturn(['data' => [], 'error' => 'release is already failed']);
        $database->shouldReceive('adminSelectResult')->once()->ordered()->andReturn(['data' => [$failed], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->ordered()->with('finish_unity_app_release_cleanup_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_upload_id' => 'private-multipart-id',
        ])->andReturn(['data' => [$cleaned], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('objectInfo')->once()->andReturn($this->objectInfo($pending, 75000000, 'completed-etag'));
        $r2->shouldReceive('abortMultipartUpload')->once()->with(
            'unity-releases', $pending['storage_path'], 'private-multipart-id',
        );
        $r2->shouldReceive('deleteObject')->once()->with('unity-releases', $pending['storage_path']);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('message', 'The upload was discarded while it was being published.');
    }

    public function test_completion_error_still_cleans_when_a_concurrent_discard_wins(): void
    {
        $pending = $this->release([
            'status' => 'uploading',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
        ]);
        $failed = $this->release([
            'status' => 'failed',
            'is_active' => false,
            'size_bytes' => null,
            'provider_upload_id' => 'private-multipart-id',
            'object_etag' => null,
        ]);
        $cleaned = array_replace($failed, ['provider_upload_id' => null]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->ordered()->andReturn(['data' => [$pending], 'error' => null]);
        $database->shouldReceive('adminSelectResult')->once()->ordered()->andReturn(['data' => [$failed], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->ordered()->with('finish_unity_app_release_cleanup_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_upload_id' => 'private-multipart-id',
        ])->andReturn(['data' => [$cleaned], 'error' => null]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('objectInfo')->once()->andThrow(new RuntimeException('R2 completion response was lost'));
        $r2->shouldReceive('listUploadedParts')->once()->andThrow(new RuntimeException('upload already completed'));
        $r2->shouldReceive('abortMultipartUpload')->once()->with(
            'unity-releases', $pending['storage_path'], 'private-multipart-id',
        );
        $r2->shouldReceive('deleteObject')->once()->with('unity-releases', $pending['storage_path']);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/'.self::RELEASE.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('message', 'The upload was discarded while it was being published.');
    }

    public function test_reactivation_rechecks_r2_size_metadata_and_etag(): void
    {
        $release = $this->release(['is_active' => false]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$release], 'error' => null]);
        $database->shouldReceive('adminRpcResult')->once()->with('activate_unity_app_release_v2', [
            'p_release_id' => self::RELEASE,
            'p_admin_id' => self::ADMIN,
            'p_verified_size_bytes' => 75000000,
            'p_object_etag' => 'stored-etag',
        ])->andReturn(['data' => [$this->release()], 'error' => null]);
        $database->shouldReceive('audit')->once()->andReturn(true);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('objectInfo')->once()->andReturn($this->objectInfo($release, 75000000, 'stored-etag'));

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->post('/admin/game-releases/'.self::RELEASE.'/activate')
            ->assertRedirect('/admin/game-releases')
            ->assertSessionHas('success');
    }

    public function test_student_r2_download_is_short_lived_and_only_for_active_release(): void
    {
        $release = $this->release();
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->with(
            'unity_app_releases', '*', ['id' => self::RELEASE, 'status' => 'ready', 'is_active' => true],
        )->andReturn(['data' => [$release], 'error' => null]);
        $database->shouldNotReceive('createStorageSignedDownload');
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('createSignedDownload')->once()->with(
            'unity-releases', $release['storage_path'], $release['download_name'], 300,
        )->andReturn('https://signed.r2.example/game.apk');

        $this->withSession(['supabase_user' => $this->user('student')])
            ->get('/game-download/'.self::RELEASE)
            ->assertRedirect('https://signed.r2.example/game.apk')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_legacy_supabase_release_downloads_remain_compatible(): void
    {
        $release = $this->release([
            'id' => self::HISTORICAL,
            'storage_provider' => 'supabase',
            'is_active' => false,
        ]);
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminSelectResult')->once()->andReturn(['data' => [$release], 'error' => null]);
        $database->shouldReceive('createStorageSignedDownload')->once()->andReturn([
            'url' => 'https://project.supabase.co/storage/v1/object/sign/unity-releases/game.apk?token=signed',
            'error' => null,
        ]);
        $this->mock(CloudflareR2Service::class);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->get('/admin/game-releases/'.self::HISTORICAL.'/download')
            ->assertRedirect('https://project.supabase.co/storage/v1/object/sign/unity-releases/game.apk?token=signed');
    }

    public function test_missing_provider_migration_has_an_exact_setup_message(): void
    {
        $database = $this->mock(SupabaseService::class);
        $database->shouldReceive('adminRpcResult')->once()->andReturn([
            'data' => [],
            'error' => 'Could not find the function public.begin_unity_app_release_v2 | PGRST202',
            'status' => 404,
        ]);
        $r2 = $this->mock(CloudflareR2Service::class);
        $r2->shouldReceive('configured')->once()->andReturn(true);
        $r2->shouldReceive('bucket')->once()->andReturn('unity-releases');
        $r2->shouldReceive('partSize')->once()->andReturn(self::PART_SIZE);

        $this->withSession(['supabase_user' => $this->user('admin')])
            ->postJson('/admin/game-releases/uploads', [
                'version' => '2.3.0', 'file_name' => 'MathVerse-2.3.0.apk', 'file_size' => 75000000,
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Game uploads are not ready. Apply database migration 2026_10_12_cloudflare_r2_app_releases.sql, then reload the page.');
    }

    /** @return array<string, array{string}> */
    public static function downloadRoles(): array
    {
        return ['student' => ['student'], 'teacher' => ['teacher']];
    }

    /** @return array<string, array{string}> */
    public static function unsupportedBuilds(): array
    {
        return ['bundle' => ['MathVerse.aab'], 'archive' => ['MathVerse.zip'], 'executable' => ['MathVerse.exe']];
    }

    /** @return array<string, array{string,string,string,string}> */
    public static function supportedBuilds(): array
    {
        return [
            'Android APK' => ['MathVerse-2.2.0.apk', 'android', 'apk', 'application/vnd.android.package-archive'],
            'iOS IPA' => ['MathVerse-2.2.0.ipa', 'ios', 'ipa', 'application/x-itunes-ipa'],
        ];
    }

    /** @return array<string, mixed> */
    private function user(string $role): array
    {
        $ids = ['student' => self::STUDENT, 'teacher' => self::TEACHER, 'admin' => self::ADMIN];

        return ['id' => $ids[$role], 'role' => $role, 'first_name' => ucfirst($role), 'last_name' => 'User', 'email' => $role.'@example.test'];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function release(array $overrides = []): array
    {
        return array_replace([
            'id' => self::RELEASE,
            'version' => '2.1.0',
            'platform' => 'android',
            'artifact_kind' => 'apk',
            'storage_provider' => 'r2',
            'storage_bucket' => 'unity-releases',
            'storage_path' => 'releases/android/'.self::RELEASE.'/mathverse-2.1.0.apk',
            'provider_upload_id' => null,
            'object_etag' => 'stored-etag',
            'multipart_part_size' => self::PART_SIZE,
            'download_name' => 'MathVerse-2.1.0.apk',
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

    /** @return array{size:int,etag:string,content_type:string,metadata:array<string,string>} */
    private function objectInfo(array $release, int $size, string $etag): array
    {
        return [
            'size' => $size,
            'etag' => $etag,
            'content_type' => (string) $release['mime_type'],
            'metadata' => [
                'release-id' => (string) $release['id'],
                'expected-size' => (string) $release['expected_size_bytes'],
            ],
        ];
    }
}
