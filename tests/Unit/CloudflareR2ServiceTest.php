<?php

namespace Tests\Unit;

use App\Services\CloudflareR2Service;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CloudflareR2ServiceTest extends TestCase
{
    private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

    private const ACCESS_KEY_ID = '0123456789abcdef0123456789abcdef';

    private const SECRET_ACCESS_KEY = 'a-very-private-r2-secret-that-must-never-be-returned';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mathverse.app_releases.r2', [
            'account_id' => self::ACCOUNT_ID,
            'access_key_id' => self::ACCESS_KEY_ID,
            'secret_access_key' => self::SECRET_ACCESS_KEY,
            'bucket' => 'unity-releases',
            'part_size' => 16 * 1024 * 1024,
            'upload_url_ttl' => 900,
            'signed_download_ttl' => 300,
        ]);
    }

    public function test_it_creates_a_private_multipart_upload_with_normalized_output(): void
    {
        $handler = new MockHandler([new Result(['UploadId' => 'r2-upload-123'])]);
        $clientConfiguration = [];
        $service = $this->service($handler, $clientConfiguration);

        $result = $service->createMultipartUpload(
            'unity-releases',
            'releases/123/MathVerse-2.4.0.apk',
            'application/vnd.android.package-archive',
            [
                'release-id' => '123',
                'expected-size' => 367001600,
            ],
        );

        $this->assertTrue($service->configured());
        $this->assertSame('unity-releases', $service->bucket());
        $this->assertSame(16 * 1024 * 1024, $service->partSize());
        $this->assertSame(['upload_id' => 'r2-upload-123'], $result);
        $this->assertStringNotContainsString(self::SECRET_ACCESS_KEY, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(
            'https://'.self::ACCOUNT_ID.'.r2.cloudflarestorage.com',
            $clientConfiguration['endpoint'],
        );

        $command = $handler->getLastCommand();
        $this->assertSame('CreateMultipartUpload', $command->getName());
        $this->assertSame('unity-releases', $command['Bucket']);
        $this->assertSame('releases/123/MathVerse-2.4.0.apk', $command['Key']);
        $this->assertSame('application/vnd.android.package-archive', $command['ContentType']);
        $this->assertSame([
            'release-id' => '123',
            'expected-size' => '367001600',
        ], $command['Metadata']);
    }

    public function test_it_signs_short_lived_part_uploads_and_private_downloads(): void
    {
        $handler = new MockHandler;
        $unused = [];
        $service = $this->service($handler, $unused);
        $before = time();

        $part = $service->createSignedPartUpload(
            'unity-releases',
            'releases/123/app.ipa',
            'upload-id-123',
            7,
            7200,
        );
        $download = $service->createSignedDownload(
            'unity-releases',
            'releases/123/app.ipa',
            'MathVerse iOS 2.4.0.ipa',
            86400,
        );

        $this->assertStringStartsWith(
            'https://unity-releases.'.self::ACCOUNT_ID.'.r2.cloudflarestorage.com/releases/123/app.ipa?',
            $part['url'],
        );
        $this->assertStringContainsString('partNumber=7', $part['url']);
        $this->assertStringContainsString('uploadId=upload-id-123', $part['url']);
        $this->assertStringContainsString('X-Amz-Expires=3600', $part['url']);
        $this->assertGreaterThanOrEqual($before + 3600, $part['expires_at']);
        $this->assertLessThanOrEqual(time() + 3600, $part['expires_at']);
        $this->assertStringContainsString('X-Amz-Expires=900', $download);
        $this->assertStringContainsString('response-content-disposition=', $download);
        $this->assertStringNotContainsString(self::SECRET_ACCESS_KEY, $part['url'].$download);
    }

    public function test_it_lists_all_uploaded_parts_across_r2_pages(): void
    {
        $handler = new MockHandler([
            new Result([
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => '"part-one"', 'Size' => 16777216],
                    ['PartNumber' => 2, 'ETag' => '"part-two"', 'Size' => 16777216],
                ],
                'IsTruncated' => true,
                'NextPartNumberMarker' => 2,
            ]),
            new Result([
                'Parts' => [
                    ['PartNumber' => 3, 'ETag' => '"part-three"', 'Size' => 4096],
                ],
                'IsTruncated' => false,
            ]),
        ]);
        $unused = [];
        $service = $this->service($handler, $unused);

        $parts = $service->listUploadedParts(
            'unity-releases',
            'releases/123/app.apk',
            'upload-id-123',
        );

        $this->assertSame([1, 2, 3], array_column($parts, 'PartNumber'));
        $this->assertSame('"part-three"', $parts[2]['ETag']);
        $this->assertSame(4096, $parts[2]['Size']);
        $this->assertSame(2, $handler->getLastCommand()['PartNumberMarker']);
        $this->assertCount(0, $handler);
    }

    public function test_it_completes_aborts_heads_and_deletes_without_returning_provider_credentials(): void
    {
        $commands = [];
        $handler = new MockHandler([
            function ($command) use (&$commands): Result {
                $commands[] = $command;

                return new Result([
                    'ETag' => '"complete-etag"',
                    'VersionId' => 'version-1',
                    'ChecksumSHA256' => 'checksum-1',
                ]);
            },
            function ($command) use (&$commands): Result {
                $commands[] = $command;

                return new Result;
            },
            function ($command) use (&$commands): Result {
                $commands[] = $command;

                return new Result([
                    'ContentLength' => 33554437,
                    'ETag' => '"object-etag"',
                    'ContentType' => 'application/octet-stream',
                    'Metadata' => [
                        'Release-Id' => 'release-123',
                        'expected-size' => '33554437',
                    ],
                ]);
            },
            function ($command) use (&$commands): Result {
                $commands[] = $command;

                return new Result;
            },
        ]);
        $unused = [];
        $service = $this->service($handler, $unused);
        $parts = [
            ['PartNumber' => 1, 'ETag' => '"part-one"', 'Size' => 16777216],
            ['PartNumber' => 2, 'ETag' => '"part-two"', 'Size' => 16777216],
            ['PartNumber' => 3, 'ETag' => '"part-three"', 'Size' => 5],
        ];

        $complete = $service->completeMultipartUpload(
            'unity-releases',
            'releases/release-123/app.apk',
            'upload-id-123',
            $parts,
        );
        $service->abortMultipartUpload(
            'unity-releases',
            'releases/release-123/abandoned.apk',
            'abandoned-upload-id',
        );
        $info = $service->objectInfo('unity-releases', 'releases/release-123/app.apk');
        $service->deleteObject('unity-releases', 'releases/release-123/abandoned.apk');

        $this->assertSame([
            'etag' => 'complete-etag',
            'version_id' => 'version-1',
            'checksum_sha256' => 'checksum-1',
        ], $complete);
        $this->assertSame([
            'size' => 33554437,
            'etag' => 'object-etag',
            'content_type' => 'application/octet-stream',
            'metadata' => [
                'release-id' => 'release-123',
                'expected-size' => '33554437',
            ],
        ], $info);
        $this->assertSame([
            'CompleteMultipartUpload',
            'AbortMultipartUpload',
            'HeadObject',
            'DeleteObject',
        ], array_map(static fn ($command): string => $command->getName(), $commands));
        $this->assertSame([
            ['ETag' => '"part-one"', 'PartNumber' => 1],
            ['ETag' => '"part-two"', 'PartNumber' => 2],
            ['ETag' => '"part-three"', 'PartNumber' => 3],
        ], $commands[0]['MultipartUpload']['Parts']);
        $this->assertStringNotContainsString(
            self::SECRET_ACCESS_KEY,
            json_encode([$complete, $info], JSON_THROW_ON_ERROR),
        );
    }

    public function test_aborting_an_already_closed_multipart_upload_is_idempotent(): void
    {
        $handler = new MockHandler([
            new AwsException(
                'The multipart upload no longer exists.',
                new Command('AbortMultipartUpload'),
                ['code' => 'NoSuchUpload', 'response' => new Response(404)],
            ),
        ]);
        $unused = [];
        $service = $this->service($handler, $unused);

        $service->abortMultipartUpload(
            'unity-releases',
            'releases/release-123/abandoned.apk',
            'already-closed-upload-id',
        );

        $this->assertSame('AbortMultipartUpload', $handler->getLastCommand()->getName());
        $this->assertCount(0, $handler);
    }

    #[DataProvider('invalidStorageLocations')]
    public function test_it_rejects_unsafe_storage_locations_before_creating_a_client(
        string $bucket,
        string $path,
    ): void {
        $clientCreated = false;
        $service = new CloudflareR2Service(function () use (&$clientCreated): S3Client {
            $clientCreated = true;
            throw new RuntimeException('The client should not be created.');
        });

        try {
            $service->objectInfo($bucket, $path);
            $this->fail('An unsafe R2 location was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid Cloudflare R2 storage location.', $exception->getMessage());
        }

        $this->assertFalse($clientCreated);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidStorageLocations(): array
    {
        return [
            'parent traversal' => ['unity-releases', 'releases/../secret.apk'],
            'encoded traversal' => ['unity-releases', 'releases/%2e%2e/secret.apk'],
            'backslash' => ['unity-releases', 'releases\\secret.apk'],
            'duplicate separator' => ['unity-releases', 'releases//app.apk'],
            'absolute path' => ['unity-releases', '/releases/app.apk'],
            'unsafe bucket' => ['unity-releases/other', 'releases/app.apk'],
        ];
    }

    public function test_it_rejects_malformed_uploads_part_lists_metadata_and_download_names(): void
    {
        $handler = new MockHandler;
        $unused = [];
        $service = $this->service($handler, $unused);

        foreach ([
            fn () => $service->createSignedPartUpload(
                'unity-releases',
                'releases/123/app.apk',
                "upload\nid",
                1,
                300,
            ),
            fn () => $service->completeMultipartUpload(
                'unity-releases',
                'releases/123/app.apk',
                'upload-id',
                [
                    ['PartNumber' => 2, 'ETag' => '"wrong-order"', 'Size' => 10],
                ],
            ),
            fn () => $service->createMultipartUpload(
                'unity-releases',
                'releases/123/app.apk',
                'application/octet-stream',
                ['release-id' => "release\r\ninjected"],
            ),
            fn () => $service->createSignedDownload(
                'unity-releases',
                'releases/123/app.apk',
                "MathVerse.apk\r\nContent-Type:text/html",
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Malformed R2 input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(0, $handler);
    }

    public function test_unconfigured_r2_fails_closed_without_initializing_an_sdk_client(): void
    {
        config()->set('mathverse.app_releases.r2.secret_access_key', '');
        $clientCreated = false;
        $service = new CloudflareR2Service(function () use (&$clientCreated): S3Client {
            $clientCreated = true;
            throw new RuntimeException('The client should not be created.');
        });

        $this->assertFalse($service->configured());

        try {
            $service->bucket();
            $this->fail('An unconfigured R2 service returned a bucket.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloudflare R2 is not configured.', $exception->getMessage());
        }

        $this->assertFalse($clientCreated);
    }

    /**
     * @param  array<string, mixed>  $clientConfiguration
     */
    private function service(MockHandler $handler, array &$clientConfiguration): CloudflareR2Service
    {
        return new CloudflareR2Service(function (array $configuration) use (
            $handler,
            &$clientConfiguration,
        ): S3Client {
            $clientConfiguration = $configuration;

            return new S3Client([...$configuration, 'handler' => $handler]);
        });
    }
}
