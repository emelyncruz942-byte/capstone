<?php

namespace Tests\Feature;

use App\Support\SquareAvatarImage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NormalizeAvatarsCommandTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const SOURCE = 'https://project.supabase.co/storage/v1/object/public/avatars/'.self::ID.'_original.jpg';
    private array $manifestsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestsBefore = glob(storage_path('app/private/avatar-normalization/*.jsonl')) ?: [];
        config(['services.supabase.url' => 'https://project.supabase.co', 'services.supabase.service_key' => 'server-only-test-key']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->newManifests() as $file) unlink($file);
        parent::tearDown();
    }

    public function test_dry_run_checks_images_but_never_uploads_changes_profiles_or_creates_a_manifest(): void
    {
        $this->fake();
        $this->assertSame(0, Artisan::call('avatars:normalize'));
        $this->assertStringContainsString('Dry run: inspected 1; ready 1; updated 0', Artisan::output());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertSame([], $this->newManifests());
    }

    public function test_apply_uploads_a_bounded_square_jpeg_and_compares_the_original_url_before_updating(): void
    {
        $this->fake();
        $this->assertSame(0, Artisan::call('avatars:normalize', ['--apply' => true]));
        $this->assertStringContainsString('updated 1', Artisan::output());
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '.jpg')
            && $request->hasHeader('Content-Type', 'image/jpeg')
            && strlen($request->body()) <= SquareAvatarImage::MAX_COMPRESSED_BYTES
            && getimagesizefromstring($request->body())[0] === 512
            && getimagesizefromstring($request->body())[1] === 512);
        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') return false;
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            return $query['id'] === 'eq.'.self::ID && $query['avatar_url'] === 'eq.'.self::SOURCE;
        });
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $records = $this->records();
        $this->assertSame(['prepared', 'uploaded', 'updated'], array_column($records, 'status'));
        $this->assertSame(self::SOURCE, $records[2]['before']);
        $this->assertStringStartsWith('https://project.supabase.co/storage/v1/object/public/avatars/'.self::ID.'_', $records[2]['after']);
        $this->assertStringNotContainsString(self::ID, Artisan::output());
        $this->assertStringNotContainsString('https://', Artisan::output());
    }

    public function test_a_concurrent_photo_change_is_never_overwritten_or_retried_without_its_filter(): void
    {
        $this->fake(conflict: true);
        $this->assertSame(1, Artisan::call('avatars:normalize', ['--apply' => true]));
        $this->assertStringContainsString('conflicts 1', Artisan::output());
        Http::assertSentCount(4);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PATCH'));
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame('not_confirmed', $this->records()[2]['status']);
    }

    public function test_failed_upload_preserves_the_original_and_does_not_patch_a_profile(): void
    {
        $this->fake(uploadStatus: 503);
        $this->assertSame(1, Artisan::call('avatars:normalize', ['--apply' => true]));
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['PATCH', 'DELETE'], true));
        $this->assertSame(['prepared', 'failed'], array_column($this->records(), 'status'));
    }

    public function test_already_compliant_jpegs_are_unchanged_on_replay(): void
    {
        $this->fake(image: $this->jpeg(256, 256));
        $this->assertSame(0, Artisan::call('avatars:normalize', ['--apply' => true]));
        $this->assertStringContainsString('unchanged 1', Artisan::output());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_external_urls_and_storage_redirects_cannot_be_followed(): void
    {
        $this->fake(source: 'https://outside.example/private.jpg');
        $this->assertSame(1, Artisan::call('avatars:normalize'));
        Http::assertSentCount(1);

        $this->fake(downloadStatus: 302);
        $this->assertSame(1, Artisan::call('avatars:normalize'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outside.example'));
    }

    public function test_oversized_or_invalid_images_cannot_be_uploaded(): void
    {
        $this->fake(declaredLength: 2 * 1024 * 1024 + 1);
        $this->assertSame(1, Artisan::call('avatars:normalize'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->fake(image: str_repeat('x', 2 * 1024 * 1024 + 1), declaredLength: 0);
        $this->assertSame(1, Artisan::call('avatars:normalize'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->fake(image: 'not an image');
        $this->assertSame(1, Artisan::call('avatars:normalize'));
        Http::assertSentCount(2);
    }

    public function test_missing_server_credentials_fail_before_any_network_request(): void
    {
        config(['services.supabase.service_key' => null]);
        $this->assertSame(1, Artisan::call('avatars:normalize', ['--apply' => true]));
        Http::assertNothingSent();
    }

    private function fake(?string $image = null, bool $conflict = false, int $uploadStatus = 200, string $source = self::SOURCE, int $downloadStatus = 200, ?int $declaredLength = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        $image ??= $this->jpeg(900, 600);
        Http::fake(function ($request) use ($image, $conflict, $uploadStatus, $source, $downloadStatus, $declaredLength) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/v1/profiles')) {
                return Http::response([['id' => self::ID, 'avatar_url' => $source]], 200, ['Content-Range' => '0-0/1']);
            }
            if ($request->method() === 'GET' && $request->url() === self::SOURCE) {
                return Http::response($image, $downloadStatus, ['Content-Type' => 'image/jpeg',
                    'Content-Length' => (string) ($declaredLength ?? strlen($image)), 'Location' => 'https://outside.example/private.jpg']);
            }
            if ($request->method() === 'POST') return Http::response(['Key' => 'uploaded'], $uploadStatus);
            if ($request->method() === 'PATCH') return Http::response($conflict ? [] : [['id' => self::ID, 'avatar_url' => $request['avatar_url']]]);
            throw new \RuntimeException('Unexpected outbound request.');
        });
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 25, 70, 135));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function newManifests(): array
    {
        return array_values(array_diff(glob(storage_path('app/private/avatar-normalization/*.jsonl')) ?: [], $this->manifestsBefore));
    }

    private function records(): array
    {
        $files = $this->newManifests();
        $this->assertCount(1, $files);
        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
