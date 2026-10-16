<?php

namespace App\Http\Controllers;

use App\Services\CloudflareR2Service;
use App\Services\SupabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class AdminAppReleaseController extends Controller
{
    private const RELEASE_TABLE = 'unity_app_releases';

    private const R2_PROVIDER = 'r2';

    private const MAX_RELEASE_BYTES = 5368709120;

    private const ALLOWED_ARTIFACTS = [
        'apk' => ['platform' => 'android', 'mime' => 'application/vnd.android.package-archive'],
        'ipa' => ['platform' => 'ios', 'mime' => 'application/x-itunes-ipa'],
    ];

    public function __construct(
        private SupabaseService $supabase,
        private CloudflareR2Service $r2,
    ) {}

    public function index()
    {
        $user = session('supabase_user', []);
        try {
            $result = $this->supabase->adminSelectResult(
                self::RELEASE_TABLE,
                '*,storage_provider,provider_upload_id,object_etag,multipart_part_size',
                ['order' => 'created_at.desc', 'limit' => 100],
            );
        } catch (\Throwable $exception) {
            Log::warning('Unity app releases could not be listed.', ['exception' => $exception::class]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $releases = is_array($result['data'] ?? null) ? $result['data'] : [];
        $releasesReady = ($result['error'] ?? null) === null;
        $uploadsReady = $releasesReady && $this->r2->configured();
        $uploadSetupMessage = ! $releasesReady
            ? 'Game uploads are unavailable because release metadata is not ready. Apply the release migrations, then reload this page.'
            : 'Cloudflare R2 is not configured. Add the private R2 bucket credentials to the deployment environment, then reload this page.';

        return view('admin.app-releases.index', compact(
            'user',
            'releases',
            'releasesReady',
            'uploadsReady',
            'uploadSetupMessage',
        ));
    }

    public function initiate(Request $request): JsonResponse
    {
        if (! $this->r2->configured()) {
            return response()->json([
                'message' => 'Cloudflare R2 is not configured for game uploads. Ask an administrator to add the R2 deployment secrets.',
            ], 503);
        }

        $maxBytes = max(1, min((int) config('mathverse.app_releases.max_bytes', 1073741824), self::MAX_RELEASE_BYTES));
        $request->merge([
            'version' => is_string($request->input('version')) ? ltrim(trim($request->input('version')), 'vV') : $request->input('version'),
            'release_notes' => is_string($request->input('release_notes')) ? trim($request->input('release_notes')) : $request->input('release_notes'),
        ]);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.-]+)?$/D'],
            'file_name' => ['required', 'string', 'min:5', 'max:220', 'regex:/^[^\/\\\\\x00-\x1F\x7F]+$/uD'],
            'file_size' => ['required', 'integer', 'min:1', "max:{$maxBytes}"],
            'release_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $extension = strtolower((string) pathinfo($validated['file_name'], PATHINFO_EXTENSION));
        $artifact = self::ALLOWED_ARTIFACTS[$extension] ?? null;
        if ($artifact === null) {
            return response()->json([
                'message' => 'Upload an Android APK or an iOS IPA build.',
                'errors' => ['file_name' => ['Only APK and IPA game builds are supported.']],
            ], 422);
        }

        $admin = session('supabase_user', []);
        $bucket = $this->r2->bucket();
        $configuredPartSize = $this->r2->partSize();
        try {
            $begin = $this->supabase->adminRpcResult('begin_unity_app_release_v2', [
                'p_admin_id' => $admin['id'] ?? null,
                'p_version' => $validated['version'],
                'p_platform' => $artifact['platform'],
                'p_artifact_kind' => $extension,
                'p_original_filename' => $validated['file_name'],
                'p_expected_size_bytes' => (int) $validated['file_size'],
                'p_release_notes' => ($validated['release_notes'] ?? null) ?: null,
                'p_mime_type' => $artifact['mime'],
                'p_multipart_part_size' => $configuredPartSize,
                'p_storage_bucket' => $bucket,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app release could not be started.', [
                'admin_id' => $admin['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $begin = ['data' => [], 'error' => 'unavailable'];
        }

        $release = $begin['data'][0] ?? null;
        if (($begin['error'] ?? null) !== null || ! is_array($release)) {
            if ($this->releaseSetupUnavailable($begin)) {
                return response()->json([
                    'message' => 'Game uploads are not ready. Apply database migration 2026_10_12_cloudflare_r2_app_releases.sql, then reload the page.',
                ], 503);
            }

            return response()->json(['message' => 'The upload could not be started. Check the version and try again.'], 409);
        }

        $uploadId = null;
        try {
            $partSize = $this->releasePartSize($release);
            $multipart = $this->r2->createMultipartUpload(
                $bucket,
                (string) $release['storage_path'],
                $artifact['mime'],
                [
                    'release-id' => (string) $release['id'],
                    'expected-size' => (string) (int) $validated['file_size'],
                    'platform' => $artifact['platform'],
                ],
            );
            $uploadId = $multipart['upload_id'];
            $attached = $this->supabase->adminRpcResult('attach_unity_app_release_upload_v2', [
                'p_release_id' => $release['id'],
                'p_admin_id' => $admin['id'] ?? null,
                'p_upload_id' => $uploadId,
            ]);
            if (($attached['error'] ?? null) !== null || ! isset($attached['data'][0]['id'])) {
                throw new UnexpectedValueException('The multipart upload could not be attached to its release.');
            }
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app multipart upload could not be prepared.', [
                'release_id' => $release['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $failed = $this->markFailed(
                (string) ($release['id'] ?? ''),
                $admin,
                'The R2 multipart upload could not be prepared.',
                true,
            );
            if ($failed) {
                $cleanupRelease = array_replace($release, [
                    'provider_upload_id' => is_string($uploadId) ? $uploadId : null,
                ]);
                $this->cleanupR2Artifact($cleanupRelease, $admin);
            }

            return response()->json(['message' => 'The secure upload could not be prepared. Please try again.'], 503);
        }

        $this->supabase->audit($admin, 'unity_release.upload_started', 'unity_app_release', $release['id'] ?? null, [
            'version' => $release['version'] ?? $validated['version'],
            'platform' => $artifact['platform'],
            'size_bytes' => (int) $validated['file_size'],
            'storage_provider' => self::R2_PROVIDER,
        ]);

        return response()->json([
            'release' => ['id' => $release['id'], 'version' => $release['version']],
            'upload' => [
                'part_size' => $partSize,
                'part_count' => (int) ceil(((int) $validated['file_size']) / $partSize),
                'sign_part_url' => route('admin.app-releases.parts.sign', ['releaseId' => $release['id']]),
            ],
        ], 201);
    }

    public function signPart(Request $request, string $releaseId): JsonResponse
    {
        $validated = $request->validate(['part_number' => ['required', 'integer', 'min:1', 'max:10000']]);
        $release = $this->release($releaseId, 'uploading');
        if ($release === null || ($release['storage_provider'] ?? null) !== self::R2_PROVIDER) {
            return response()->json(['message' => 'That pending R2 upload was not found.'], 404);
        }

        try {
            $partSize = $this->releasePartSize($release);
        } catch (\Throwable) {
            return response()->json(['message' => 'That upload has invalid multipart settings. Discard it and start again.'], 422);
        }
        $partCount = (int) ceil(((int) ($release['expected_size_bytes'] ?? 0)) / $partSize);
        if ((int) $validated['part_number'] > $partCount) {
            return response()->json(['message' => 'That upload part is outside the expected file size.'], 422);
        }

        $uploadId = (string) ($release['provider_upload_id'] ?? '');
        try {
            $signed = $this->r2->createSignedPartUpload(
                (string) $release['storage_bucket'],
                (string) $release['storage_path'],
                $uploadId,
                (int) $validated['part_number'],
                (int) config('mathverse.app_releases.r2.upload_url_ttl', 3600),
            );
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app part URL could not be signed.', [
                'release_id' => $releaseId,
                'part_number' => (int) $validated['part_number'],
                'exception' => $exception::class,
            ]);

            return response()->json(['message' => 'That upload part could not be authorized. Please retry.'], 503);
        }

        return response()->json($signed);
    }

    public function complete(Request $request, string $releaseId)
    {
        $admin = session('supabase_user', []);
        $release = $this->release($releaseId, 'uploading');
        if ($release === null) {
            return $this->mutationResponse($request, 'That pending upload was not found.', 404);
        }

        if (($release['storage_provider'] ?? 'supabase') === self::R2_PROVIDER) {
            return $this->completeR2($request, $release, $admin);
        }

        return $this->completeLegacySupabase($request, $release, $admin);
    }

    public function fail(Request $request, string $releaseId)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $admin = session('supabase_user', []);
        $release = $this->release($releaseId, 'uploading')
            ?? $this->release($releaseId, 'failed');
        $isR2 = is_array($release) && ($release['storage_provider'] ?? null) === self::R2_PROVIDER;
        $reason = trim((string) ($validated['reason'] ?? 'Upload interrupted.'));
        $failed = $this->markFailed(
            $releaseId,
            $admin,
            $reason ?: 'Upload interrupted.',
            $isR2,
        );

        $cleanupFinished = true;
        if ($failed && $isR2) {
            $cleanupFinished = $this->cleanupR2Artifact($release, $admin);
        }

        if ($failed && ! $cleanupFinished) {
            return $this->mutationResponse(
                $request,
                'The release is marked failed, but R2 storage cleanup did not finish. Use Retry storage cleanup to try again.',
                503,
            );
        }

        return $this->mutationResponse(
            $request,
            $failed ? 'The interrupted upload was discarded.' : 'The upload status could not be updated.',
            $failed ? 200 : 422,
        );
    }

    public function activate(string $releaseId)
    {
        $admin = session('supabase_user', []);
        $candidate = $this->release($releaseId, 'ready');
        if ($candidate === null) {
            return back()->with('error', 'That game version could not be made current.');
        }

        try {
            if (($candidate['storage_provider'] ?? 'supabase') === self::R2_PROVIDER) {
                $info = $this->r2->objectInfo((string) $candidate['storage_bucket'], (string) $candidate['storage_path']);
                $this->assertR2ObjectMatches($candidate, $info, true);
                $result = $this->supabase->adminRpcResult('activate_unity_app_release_v2', [
                    'p_release_id' => $releaseId,
                    'p_admin_id' => $admin['id'] ?? null,
                    'p_verified_size_bytes' => $info['size'],
                    'p_object_etag' => $info['etag'],
                ]);
            } else {
                $result = $this->supabase->adminRpcResult('activate_unity_app_release', [
                    'p_release_id' => $releaseId,
                    'p_admin_id' => $admin['id'] ?? null,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('A Unity app release could not be activated.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $release = $result['data'][0] ?? null;
        if (($result['error'] ?? null) !== null || ! is_array($release)) {
            return back()->with('error', 'That game version could not be verified and made current.');
        }

        $this->supabase->audit($admin, 'unity_release.activated', 'unity_app_release', $releaseId, [
            'version' => $release['version'] ?? null,
        ]);

        return redirect()->route('admin.app-releases.index')
            ->with('success', 'Game version '.($release['version'] ?? '').' is now current.');
    }

    public function download(string $releaseId)
    {
        $release = $this->release($releaseId, 'ready');
        if ($release === null) {
            return redirect()->route('admin.app-releases.index')->with('error', 'That game version is not available for download.');
        }

        $url = $this->signedDownloadUrl($release);
        if ($url === null) {
            return redirect()->route('admin.app-releases.index')->with('error', 'The download link could not be created. Please try again.');
        }

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    private function completeR2(Request $request, array $release, array $admin)
    {
        $releaseId = (string) $release['id'];
        $bucket = (string) $release['storage_bucket'];
        $path = (string) $release['storage_path'];
        $info = null;

        try {
            try {
                $info = $this->r2->objectInfo($bucket, $path);
            } catch (\Throwable) {
                $info = null;
            }
            if ($info === null) {
                $uploadId = (string) ($release['provider_upload_id'] ?? '');
                $parts = $this->r2->listUploadedParts($bucket, $path, $uploadId);
                $this->assertCompletePartSet($release, $parts);
                $this->r2->completeMultipartUpload($bucket, $path, $uploadId, $parts);
                $info = $this->r2->objectInfo($bucket, $path);
            }
            $this->assertR2ObjectMatches($release, $info, false);
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app upload could not be completed or verified.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);

            $cleanupFinished = $this->cleanupIfReleaseFailed($release, $admin);
            if ($cleanupFinished !== null) {
                return $this->mutationResponse(
                    $request,
                    $cleanupFinished
                        ? 'The upload was discarded while it was being published.'
                        : 'The upload was discarded, but R2 storage cleanup did not finish. Use Retry storage cleanup to try again.',
                    $cleanupFinished ? 409 : 503,
                );
            }

            return $this->mutationResponse($request, 'The uploaded file is incomplete or could not be verified. Retry the upload or discard it.', 422);
        }

        try {
            $finalized = $this->supabase->adminRpcResult('finalize_unity_app_release_v2', [
                'p_release_id' => $releaseId,
                'p_admin_id' => $admin['id'] ?? null,
                'p_actual_size_bytes' => $info['size'],
                'p_object_etag' => $info['etag'],
                'p_sha256' => null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app release could not be published.', ['release_id' => $releaseId, 'exception' => $exception::class]);
            $finalized = ['data' => [], 'error' => 'unavailable'];
        }

        if (($finalized['error'] ?? null) !== null || ! is_array($finalized['data'][0] ?? null)) {
            $cleanupFinished = $this->cleanupIfReleaseFailed($release, $admin);
            if ($cleanupFinished !== null) {
                return $this->mutationResponse(
                    $request,
                    $cleanupFinished
                        ? 'The upload was discarded while it was being published.'
                        : 'The upload was discarded, but R2 storage cleanup did not finish. Use Retry storage cleanup to try again.',
                    $cleanupFinished ? 409 : 503,
                );
            }
        }

        return $this->finishPublicationResponse($request, $finalized, $admin, $releaseId, (int) $info['size']);
    }

    private function completeLegacySupabase(Request $request, array $release, array $admin)
    {
        try {
            $info = $this->supabase->storageObjectInfo((string) $release['storage_bucket'], (string) $release['storage_path']);
        } catch (\Throwable $exception) {
            Log::warning('A legacy Unity app upload could not be verified.', ['release_id' => $release['id'], 'exception' => $exception::class]);
            $info = ['data' => null, 'error' => 'unavailable'];
        }
        $actualSize = filter_var($info['data']['size'] ?? $info['data']['metadata']['size'] ?? $info['data']['contentLength'] ?? null, FILTER_VALIDATE_INT);
        if (($info['error'] ?? null) !== null || $actualSize === false || $actualSize < 1) {
            return $this->mutationResponse($request, 'The uploaded file could not be verified.', 422);
        }

        try {
            $finalized = $this->supabase->adminRpcResult('finalize_unity_app_release', [
                'p_release_id' => $release['id'],
                'p_admin_id' => $admin['id'] ?? null,
                'p_actual_size_bytes' => $actualSize,
                'p_sha256' => null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A legacy Unity app release could not be published.', ['release_id' => $release['id'], 'exception' => $exception::class]);
            $finalized = ['data' => [], 'error' => 'unavailable'];
        }

        return $this->finishPublicationResponse($request, $finalized, $admin, (string) $release['id'], (int) $actualSize);
    }

    private function finishPublicationResponse(Request $request, array $finalized, array $admin, string $releaseId, int $actualSize)
    {
        $published = $finalized['data'][0] ?? null;
        if (($finalized['error'] ?? null) !== null || ! is_array($published)) {
            return $this->mutationResponse($request, 'The upload finished, but the version could not be published. Try Finish publishing again.', 422);
        }
        $this->supabase->audit($admin, 'unity_release.published', 'unity_app_release', $releaseId, [
            'version' => $published['version'] ?? null,
            'size_bytes' => $actualSize,
        ]);

        return $this->mutationResponse($request, 'The new game version is now available.', 200, ['release' => $published]);
    }

    /** @param list<array{PartNumber:int,ETag:string,Size:int}> $parts */
    private function assertCompletePartSet(array $release, array $parts): void
    {
        $expectedSize = (int) ($release['expected_size_bytes'] ?? 0);
        $partSize = $this->releasePartSize($release);
        $expectedCount = (int) ceil($expectedSize / $partSize);
        if (count($parts) !== $expectedCount) {
            throw new UnexpectedValueException('The R2 multipart upload is incomplete.');
        }

        $total = 0;
        foreach ($parts as $index => $part) {
            $expectedPartSize = $index === $expectedCount - 1
                ? $expectedSize - ($partSize * $index)
                : $partSize;
            if ($part['PartNumber'] !== $index + 1 || $part['Size'] !== $expectedPartSize) {
                throw new UnexpectedValueException('The R2 multipart upload does not match the expected build.');
            }
            $total += $part['Size'];
        }
        if ($total !== $expectedSize) {
            throw new UnexpectedValueException('The R2 multipart upload size does not match.');
        }
    }

    private function assertR2ObjectMatches(array $release, array $info, bool $requireStoredEtag): void
    {
        $expectedSize = (int) ($release['expected_size_bytes'] ?? 0);
        $etag = $info['etag'] ?? null;
        $metadata = $info['metadata'] ?? [];
        if (($info['size'] ?? null) !== $expectedSize
            || ! is_string($etag) || $etag === ''
            || ($info['content_type'] ?? null) !== ($release['mime_type'] ?? null)
            || ($metadata['release-id'] ?? null) !== ($release['id'] ?? null)
            || ($metadata['expected-size'] ?? null) !== (string) $expectedSize
        ) {
            throw new UnexpectedValueException('The R2 object does not match this release.');
        }
        if ($requireStoredEtag && ($release['object_etag'] ?? null) !== $etag) {
            throw new UnexpectedValueException('The R2 object identity changed after publication.');
        }
    }

    private function signedDownloadUrl(array $release): ?string
    {
        try {
            if (($release['storage_provider'] ?? 'supabase') === self::R2_PROVIDER) {
                return $this->r2->createSignedDownload(
                    (string) $release['storage_bucket'],
                    (string) $release['storage_path'],
                    (string) $release['download_name'],
                    (int) config('mathverse.app_releases.r2.signed_download_ttl', 300),
                );
            }
            $signed = $this->supabase->createStorageSignedDownload(
                (string) ($release['storage_bucket'] ?? config('mathverse.app_releases.bucket', 'unity-releases')),
                (string) $release['storage_path'],
                (string) $release['download_name'],
                (int) config('mathverse.app_releases.signed_download_ttl', 300),
            );

            return ($signed['error'] ?? null) === null && is_string($signed['url'] ?? null) ? $signed['url'] : null;
        } catch (\Throwable $exception) {
            Log::warning('A Unity app download could not be signed.', [
                'release_id' => $release['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function cleanupR2Artifact(array $release, array $admin): bool
    {
        $releaseId = (string) ($release['id'] ?? '');
        $bucket = (string) ($release['storage_bucket'] ?? '');
        $path = (string) ($release['storage_path'] ?? '');
        $uploadId = (string) ($release['provider_upload_id'] ?? '');

        $multipartClosed = true;
        if ($uploadId !== '') {
            try {
                $this->r2->abortMultipartUpload($bucket, $path, $uploadId);
            } catch (\Throwable $exception) {
                $multipartClosed = false;
                Log::warning('An R2 multipart upload could not be aborted during cleanup.', [
                    'release_id' => $releaseId,
                    'exception' => $exception::class,
                ]);
            }
        }

        $objectDeleted = false;
        try {
            $this->r2->deleteObject($bucket, $path);
            $objectDeleted = true;
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app object still needs cleanup.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);

        }

        if (! $multipartClosed || ! $objectDeleted) {
            return false;
        }

        if ($uploadId === '') {
            return true;
        }

        try {
            $cleared = $this->supabase->adminRpcResult('finish_unity_app_release_cleanup_v2', [
                'p_release_id' => $releaseId,
                'p_admin_id' => $admin['id'] ?? null,
                'p_upload_id' => $uploadId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('An R2 Unity app cleanup marker could not be cleared.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $cleared = ['data' => [], 'error' => 'unavailable'];
        }

        return ($cleared['error'] ?? null) === null
            && isset($cleared['data'][0]['id'])
            && ($cleared['data'][0]['provider_upload_id'] ?? null) === null;
    }

    private function sameR2StorageLocation(array $expected, mixed $actual): bool
    {
        return is_array($actual)
            && ($actual['storage_provider'] ?? null) === self::R2_PROVIDER
            && ($actual['id'] ?? null) === ($expected['id'] ?? null)
            && ($actual['storage_bucket'] ?? null) === ($expected['storage_bucket'] ?? null)
            && ($actual['storage_path'] ?? null) === ($expected['storage_path'] ?? null);
    }

    private function cleanupIfReleaseFailed(array $release, array $admin): ?bool
    {
        $failedRelease = $this->release((string) ($release['id'] ?? ''), 'failed');
        if (! $this->sameR2StorageLocation($release, $failedRelease)) {
            return null;
        }

        return $this->cleanupR2Artifact($failedRelease, $admin);
    }

    private function releasePartSize(array $release): int
    {
        $partSize = filter_var($release['multipart_part_size'] ?? null, FILTER_VALIDATE_INT);
        if ($partSize === false || $partSize < 5 * 1024 * 1024 || $partSize > 512 * 1024 * 1024) {
            throw new UnexpectedValueException('The persisted R2 multipart part size is invalid.');
        }

        return $partSize;
    }

    private function release(string $releaseId, string $status): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $releaseId) !== 1) {
            return null;
        }
        try {
            $result = $this->supabase->adminSelectResult(self::RELEASE_TABLE, '*', ['id' => $releaseId, 'status' => $status]);
        } catch (\Throwable) {
            return null;
        }
        $release = $result['data'][0] ?? null;

        return ($result['error'] ?? null) === null && is_array($release) ? $release : null;
    }

    private function markFailed(string $releaseId, array $admin, string $reason, bool $isR2 = false): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $releaseId) !== 1) {
            return false;
        }
        try {
            $result = $this->supabase->adminRpcResult(
                $isR2 ? 'fail_unity_app_release_v2' : 'fail_unity_app_release',
                [
                    'p_release_id' => $releaseId,
                    'p_admin_id' => $admin['id'] ?? null,
                    'p_failure_reason' => mb_substr($reason, 0, 500),
                ],
            );
        } catch (\Throwable) {
            return false;
        }

        return ($result['error'] ?? null) === null && isset($result['data'][0]['id']);
    }

    /** @param array{error?: mixed, status?: mixed} $result */
    private function releaseSetupUnavailable(array $result): bool
    {
        $status = filter_var($result['status'] ?? null, FILTER_VALIDATE_INT);
        $error = strtolower((string) ($result['error'] ?? ''));

        return $status === 404 || str_contains($error, 'pgrst202') || str_contains($error, 'could not find the function')
            || (str_contains($error, 'begin_unity_app_release_v2') && str_contains($error, 'does not exist'));
    }

    private function mutationResponse(Request $request, string $message, int $status, array $payload = [])
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $payload, $status);
        }

        return redirect()->route('admin.app-releases.index')->with($status >= 400 ? 'error' : 'success', $message);
    }
}
