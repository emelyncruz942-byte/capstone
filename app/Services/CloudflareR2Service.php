<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class CloudflareR2Service
{
    private const BUCKET_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/D';

    private const OBJECT_PATH_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9._/-]{0,1023}$#D';

    private const ACCOUNT_ID_PATTERN = '/^[a-f0-9]{32}$/D';

    private const MIN_PART_SIZE = 5 * 1024 * 1024;

    private const MAX_PART_SIZE = 512 * 1024 * 1024;

    private const MAX_PART_NUMBER = 10_000;

    private readonly string $accountId;

    private readonly string $accessKeyId;

    private readonly string $secretAccessKey;

    private readonly string $configuredBucket;

    private readonly int $configuredPartSize;

    /** @var Closure(array<string, mixed>): S3ClientInterface */
    private readonly Closure $clientFactory;

    private ?S3ClientInterface $client = null;

    public function __construct(?callable $clientFactory = null)
    {
        $this->accountId = trim((string) config('mathverse.app_releases.r2.account_id', ''));
        $this->accessKeyId = trim((string) config('mathverse.app_releases.r2.access_key_id', ''));
        $this->secretAccessKey = trim((string) config('mathverse.app_releases.r2.secret_access_key', ''));
        $this->configuredBucket = trim((string) config(
            'mathverse.app_releases.r2.bucket',
            config('mathverse.app_releases.bucket', 'unity-releases'),
        ));
        $this->configuredPartSize = max(
            self::MIN_PART_SIZE,
            min(
                (int) config('mathverse.app_releases.r2.part_size', 16 * 1024 * 1024),
                self::MAX_PART_SIZE,
            ),
        );

        $this->clientFactory = $clientFactory === null
            ? static fn (array $configuration): S3ClientInterface => new S3Client($configuration)
            : Closure::fromCallable($clientFactory);
    }

    public function configured(): bool
    {
        return preg_match(self::ACCOUNT_ID_PATTERN, $this->accountId) === 1
            && $this->isSafeCredential($this->accessKeyId, 16, 128)
            && $this->isSafeCredential($this->secretAccessKey, 32, 256)
            && $this->isValidBucket($this->configuredBucket);
    }

    public function bucket(): string
    {
        $this->assertConfigured();

        return $this->configuredBucket;
    }

    public function partSize(): int
    {
        return $this->configuredPartSize;
    }

    /** @return array{upload_id: string} */
    public function createMultipartUpload(
        string $bucket,
        string $path,
        string $contentType,
        array $metadata,
    ): array {
        $this->assertStorageLocation($bucket, $path);
        $this->assertContentType($contentType);
        $metadata = $this->normalizeMetadata($metadata);

        $result = $this->client()->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $path,
            'ContentType' => $contentType,
            'Metadata' => $metadata,
        ]);
        $uploadId = $result->get('UploadId');

        if (! is_string($uploadId) || ! $this->isValidUploadId($uploadId)) {
            throw new UnexpectedValueException('Cloudflare R2 returned an invalid multipart upload identifier.');
        }

        return ['upload_id' => $uploadId];
    }

    /** @return array{url: string, expires_at: int} */
    public function createSignedPartUpload(
        string $bucket,
        string $path,
        string $uploadId,
        int $partNumber,
        int $expiresIn,
    ): array {
        $this->assertStorageLocation($bucket, $path);
        $this->assertUploadId($uploadId);
        $this->assertPartNumber($partNumber);
        $expiresIn = max(60, min($expiresIn, 3600));

        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);
        $request = $this->client()->createPresignedRequest($command, "+{$expiresIn} seconds");

        return [
            'url' => (string) $request->getUri(),
            'expires_at' => time() + $expiresIn,
        ];
    }

    /** @return list<array{PartNumber: int, ETag: string, Size: int}> */
    public function listUploadedParts(string $bucket, string $path, string $uploadId): array
    {
        $this->assertStorageLocation($bucket, $path);
        $this->assertUploadId($uploadId);

        $parts = [];
        $marker = null;
        $pageCount = 0;

        do {
            $arguments = [
                'Bucket' => $bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
                'MaxParts' => 1000,
            ];
            if ($marker !== null) {
                $arguments['PartNumberMarker'] = $marker;
            }

            $result = $this->client()->listParts($arguments);
            $pageParts = $result->get('Parts');
            if ($pageParts !== null && ! is_array($pageParts)) {
                throw new UnexpectedValueException('Cloudflare R2 returned an invalid multipart parts list.');
            }

            foreach ($pageParts ?? [] as $part) {
                if (! is_array($part)) {
                    throw new UnexpectedValueException('Cloudflare R2 returned an invalid multipart part.');
                }

                $partNumber = filter_var($part['PartNumber'] ?? null, FILTER_VALIDATE_INT);
                $size = filter_var($part['Size'] ?? null, FILTER_VALIDATE_INT);
                $etag = $part['ETag'] ?? null;
                if (! is_int($partNumber)
                    || $partNumber < 1
                    || $partNumber > self::MAX_PART_NUMBER
                    || ! is_int($size)
                    || $size < 1
                    || ! is_string($etag)
                    || ! $this->isValidEtag($etag)
                ) {
                    throw new UnexpectedValueException('Cloudflare R2 returned invalid multipart part metadata.');
                }

                $parts[] = [
                    'PartNumber' => $partNumber,
                    'ETag' => $etag,
                    'Size' => $size,
                ];
            }

            $isTruncated = $result->get('IsTruncated') === true;
            if (! $isTruncated) {
                break;
            }

            $nextMarker = filter_var($result->get('NextPartNumberMarker'), FILTER_VALIDATE_INT);
            if (! is_int($nextMarker)
                || $nextMarker < 1
                || $nextMarker > self::MAX_PART_NUMBER
                || ($marker !== null && $nextMarker <= $marker)
            ) {
                throw new UnexpectedValueException('Cloudflare R2 returned an invalid multipart pagination marker.');
            }
            $marker = $nextMarker;
            $pageCount++;
        } while ($pageCount <= self::MAX_PART_NUMBER);

        if ($pageCount > self::MAX_PART_NUMBER || count($parts) > self::MAX_PART_NUMBER) {
            throw new UnexpectedValueException('Cloudflare R2 returned too many multipart upload parts.');
        }

        usort($parts, static fn (array $left, array $right): int => $left['PartNumber'] <=> $right['PartNumber']);

        return $parts;
    }

    /**
     * @param  list<array{PartNumber: int, ETag: string, Size?: int}>  $parts
     * @return array{etag: ?string, version_id: ?string, checksum_sha256: ?string}
     */
    public function completeMultipartUpload(
        string $bucket,
        string $path,
        string $uploadId,
        array $parts,
    ): array {
        $this->assertStorageLocation($bucket, $path);
        $this->assertUploadId($uploadId);
        $parts = $this->normalizeCompletionParts($parts);

        $result = $this->client()->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $path,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(
                    static fn (array $part): array => [
                        'ETag' => $part['ETag'],
                        'PartNumber' => $part['PartNumber'],
                    ],
                    $parts,
                ),
            ],
        ]);

        return [
            'etag' => $this->nullableEtag($result->get('ETag')),
            'version_id' => $this->nullableSafeProviderValue($result->get('VersionId')),
            'checksum_sha256' => $this->nullableSafeProviderValue($result->get('ChecksumSHA256')),
        ];
    }

    public function abortMultipartUpload(string $bucket, string $path, string $uploadId): void
    {
        $this->assertStorageLocation($bucket, $path);
        $this->assertUploadId($uploadId);

        try {
            $this->client()->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchUpload' || $exception->getStatusCode() === 404) {
                return;
            }

            throw $exception;
        }
    }

    /** @return array{size: int, etag: ?string, content_type: ?string, metadata: array<string, string>} */
    public function objectInfo(string $bucket, string $path): array
    {
        $this->assertStorageLocation($bucket, $path);

        $result = $this->client()->headObject([
            'Bucket' => $bucket,
            'Key' => $path,
        ]);
        $size = filter_var($result->get('ContentLength'), FILTER_VALIDATE_INT);
        if (! is_int($size) || $size < 0) {
            throw new UnexpectedValueException('Cloudflare R2 returned an invalid object size.');
        }

        $metadata = $result->get('Metadata');
        if ($metadata === null) {
            $metadata = [];
        }
        if (! is_array($metadata)) {
            throw new UnexpectedValueException('Cloudflare R2 returned invalid object metadata.');
        }

        return [
            'size' => $size,
            'etag' => $this->nullableEtag($result->get('ETag')),
            'content_type' => $this->nullableContentType($result->get('ContentType')),
            'metadata' => $this->normalizeProviderMetadata($metadata),
        ];
    }

    public function deleteObject(string $bucket, string $path): void
    {
        $this->assertStorageLocation($bucket, $path);

        $this->client()->deleteObject([
            'Bucket' => $bucket,
            'Key' => $path,
        ]);
    }

    public function createSignedDownload(
        string $bucket,
        string $path,
        string $downloadName,
        int $expiresIn = 300,
    ): string {
        $this->assertStorageLocation($bucket, $path);
        $this->assertDownloadName($downloadName);
        $expiresIn = max(60, min($expiresIn, 900));

        $command = $this->client()->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $path,
            'ResponseContentDisposition' => "attachment; filename*=UTF-8''".rawurlencode($downloadName),
            'ResponseContentType' => 'application/octet-stream',
        ]);
        $request = $this->client()->createPresignedRequest($command, "+{$expiresIn} seconds");

        return (string) $request->getUri();
    }

    private function client(): S3ClientInterface
    {
        $this->assertConfigured();

        if ($this->client !== null) {
            return $this->client;
        }

        $factory = $this->clientFactory;
        $client = $factory([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => "https://{$this->accountId}.r2.cloudflarestorage.com",
            'credentials' => [
                'key' => $this->accessKeyId,
                'secret' => $this->secretAccessKey,
            ],
            'use_path_style_endpoint' => false,
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 60,
            ],
        ]);

        if (! $client instanceof S3ClientInterface) {
            throw new RuntimeException('The Cloudflare R2 client could not be initialized.');
        }

        return $this->client = $client;
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Cloudflare R2 is not configured.');
        }
    }

    private function assertStorageLocation(string $bucket, string $path): void
    {
        if (! $this->isValidBucket($bucket)
            || preg_match(self::OBJECT_PATH_PATTERN, $path) !== 1
            || str_contains($path, '//')
            || str_ends_with($path, '/')
        ) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 storage location.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid Cloudflare R2 storage location.');
            }
        }
    }

    private function isValidBucket(string $bucket): bool
    {
        return preg_match(self::BUCKET_PATTERN, $bucket) === 1
            && ! str_contains($bucket, '--');
    }

    private function assertContentType(string $contentType): void
    {
        if (strlen($contentType) < 3
            || strlen($contentType) > 127
            || preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D', $contentType) !== 1
        ) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 content type.');
        }
    }

    /** @return array<string, string> */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        if (count($metadata) > 16) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 object metadata.');
        }

        foreach ($metadata as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $key) !== 1
                || ! is_scalar($value)
            ) {
                throw new InvalidArgumentException('Invalid Cloudflare R2 object metadata.');
            }
            $value = (string) $value;
            if (strlen($value) > 1024 || preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid Cloudflare R2 object metadata.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function normalizeProviderMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new UnexpectedValueException('Cloudflare R2 returned invalid object metadata.');
            }
            $normalized[strtolower($key)] = (string) $value;
        }

        return $normalized;
    }

    private function assertUploadId(string $uploadId): void
    {
        if (! $this->isValidUploadId($uploadId)) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 multipart upload identifier.');
        }
    }

    private function isValidUploadId(string $uploadId): bool
    {
        return strlen($uploadId) >= 1
            && strlen($uploadId) <= 2048
            && preg_match('/^[\x21-\x7E]+$/D', $uploadId) === 1;
    }

    private function assertPartNumber(int $partNumber): void
    {
        if ($partNumber < 1 || $partNumber > self::MAX_PART_NUMBER) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 multipart part number.');
        }
    }

    /**
     * @param  array<int, mixed>  $parts
     * @return list<array{PartNumber: int, ETag: string, Size?: int}>
     */
    private function normalizeCompletionParts(array $parts): array
    {
        if ($parts === [] || count($parts) > self::MAX_PART_NUMBER || ! array_is_list($parts)) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 multipart completion list.');
        }

        $normalized = [];
        foreach ($parts as $index => $part) {
            if (! is_array($part)
                || ($part['PartNumber'] ?? null) !== $index + 1
                || ! is_string($part['ETag'] ?? null)
                || ! $this->isValidEtag($part['ETag'])
            ) {
                throw new InvalidArgumentException('Invalid Cloudflare R2 multipart completion list.');
            }
            if (array_key_exists('Size', $part)
                && (! is_int($part['Size']) || $part['Size'] < 1)
            ) {
                throw new InvalidArgumentException('Invalid Cloudflare R2 multipart completion list.');
            }

            $normalized[] = $part;
        }

        return $normalized;
    }

    private function isValidEtag(string $etag): bool
    {
        return strlen($etag) >= 1
            && strlen($etag) <= 256
            && preg_match('/^[\x21-\x7E]+$/D', $etag) === 1;
    }

    private function nullableEtag(mixed $etag): ?string
    {
        if ($etag === null) {
            return null;
        }
        if (! is_string($etag) || ! $this->isValidEtag($etag)) {
            throw new UnexpectedValueException('Cloudflare R2 returned an invalid object identifier.');
        }

        return trim($etag, '"');
    }

    private function nullableContentType(mixed $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }
        if (! is_string($contentType)) {
            throw new UnexpectedValueException('Cloudflare R2 returned an invalid object content type.');
        }
        $this->assertContentType($contentType);

        return $contentType;
    }

    private function nullableSafeProviderValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)
            || strlen($value) > 2048
            || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1
        ) {
            throw new UnexpectedValueException('Cloudflare R2 returned invalid completion metadata.');
        }

        return $value;
    }

    private function assertDownloadName(string $downloadName): void
    {
        if (! mb_check_encoding($downloadName, 'UTF-8')
            || mb_strlen($downloadName, 'UTF-8') < 5
            || mb_strlen($downloadName, 'UTF-8') > 220
            || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $downloadName) !== 0
        ) {
            throw new InvalidArgumentException('Invalid Cloudflare R2 download name.');
        }
    }

    private function isSafeCredential(string $credential, int $minimum, int $maximum): bool
    {
        $length = strlen($credential);

        return $length >= $minimum
            && $length <= $maximum
            && preg_match('/^[\x21-\x7E]+$/D', $credential) === 1;
    }
}
