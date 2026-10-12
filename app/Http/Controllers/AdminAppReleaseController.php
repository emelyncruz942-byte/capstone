<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAppReleaseController extends Controller
{
    private const RELEASE_TABLE = 'unity_app_releases';

    private const MAX_RELEASE_BYTES = 5368709120;

    private const ALLOWED_ARTIFACTS = [
        'apk' => [
            'platform' => 'android',
            'mime' => 'application/vnd.android.package-archive',
        ],
        'ipa' => [
            'platform' => 'ios',
            'mime' => 'application/x-itunes-ipa',
        ],
    ];

    public function __construct(private SupabaseService $supabase) {}

    public function index()
    {
        $user = session('supabase_user', []);

        try {
            $result = $this->supabase->adminSelectResult(
                self::RELEASE_TABLE,
                '*',
                ['order' => 'created_at.desc', 'limit' => 100],
            );
        } catch (\Throwable $exception) {
            Log::warning('Unity app releases could not be listed.', [
                'exception' => $exception::class,
            ]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $releases = $result['data'];
        $releasesReady = ($result['error'] ?? null) === null;

        return view('admin.app-releases.index', compact('user', 'releases', 'releasesReady'));
    }

    public function initiate(Request $request): JsonResponse
    {
        $maxBytes = max(1, min(
            (int) config('mathverse.app_releases.max_bytes', 1073741824),
            self::MAX_RELEASE_BYTES,
        ));
        $request->merge([
            'version' => is_string($request->input('version'))
                ? ltrim(trim($request->input('version')), 'vV')
                : $request->input('version'),
            'release_notes' => is_string($request->input('release_notes'))
                ? trim($request->input('release_notes'))
                : $request->input('release_notes'),
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
        try {
            $begin = $this->supabase->adminRpcResult('begin_unity_app_release', [
                'p_admin_id' => $admin['id'] ?? null,
                'p_version' => $validated['version'],
                'p_platform' => $artifact['platform'],
                'p_artifact_kind' => $extension,
                'p_original_filename' => $validated['file_name'],
                'p_expected_size_bytes' => (int) $validated['file_size'],
                'p_release_notes' => ($validated['release_notes'] ?? null) ?: null,
                'p_mime_type' => $artifact['mime'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A Unity app release could not be started.', [
                'admin_id' => $admin['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $begin = ['data' => [], 'error' => 'unavailable'];
        }

        $release = $begin['data'][0] ?? null;
        if (($begin['error'] ?? null) !== null || !is_array($release)) {
            if ($this->releaseSetupUnavailable($begin)) {
                return response()->json([
                    'message' => 'Game uploads are not ready. Apply database migration 2026_10_11_versioned_unity_app_releases.sql, then reload the page.',
                ], 503);
            }

            return response()->json([
                'message' => 'The upload could not be started. Check the version and try again.',
            ], 409);
        }

        try {
            $upload = $this->supabase->createStorageSignedUpload(
                (string) ($release['storage_bucket'] ?? config('mathverse.app_releases.bucket', 'unity-releases')),
                (string) ($release['storage_path'] ?? ''),
            );
        } catch (\Throwable $exception) {
            Log::warning('A signed Unity app upload could not be created.', [
                'release_id' => $release['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $upload = ['data' => null, 'error' => 'unavailable'];
        }

        if (($upload['error'] ?? null) !== null || !is_array($upload['data'] ?? null)) {
            $this->markFailed((string) ($release['id'] ?? ''), $admin, 'The storage upload could not be prepared.');

            return response()->json([
                'message' => 'The secure upload could not be prepared. Please try again.',
            ], 503);
        }

        $this->supabase->audit($admin, 'unity_release.upload_started', 'unity_app_release', $release['id'] ?? null, [
            'version' => $release['version'] ?? $validated['version'],
            'platform' => $artifact['platform'],
            'size_bytes' => (int) $validated['file_size'],
        ]);

        return response()->json([
            'release' => [
                'id' => $release['id'],
                'version' => $release['version'],
            ],
            'upload' => array_merge($upload['data'], [
                'content_type' => $artifact['mime'],
            ]),
        ], 201);
    }

    public function complete(Request $request, string $releaseId)
    {
        $admin = session('supabase_user', []);
        $release = $this->release($releaseId, 'uploading');
        if ($release === null) {
            return $this->mutationResponse(
                $request,
                'That pending upload was not found.',
                404,
            );
        }

        try {
            $info = $this->supabase->storageObjectInfo(
                (string) ($release['storage_bucket'] ?? config('mathverse.app_releases.bucket', 'unity-releases')),
                (string) $release['storage_path'],
            );
        } catch (\Throwable $exception) {
            Log::warning('A Unity app upload could not be verified.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $info = ['data' => null, 'error' => 'unavailable'];
        }

        $actualSize = filter_var(
            $info['data']['size']
                ?? $info['data']['metadata']['size']
                ?? $info['data']['contentLength']
                ?? null,
            FILTER_VALIDATE_INT,
        );
        if (($info['error'] ?? null) !== null || $actualSize === false || $actualSize < 1) {
            return $this->mutationResponse(
                $request,
                'The uploaded file could not be verified. Resume the upload or try again.',
                422,
            );
        }

        try {
            $finalized = $this->supabase->adminRpcResult('finalize_unity_app_release', [
                'p_release_id' => $releaseId,
                'p_admin_id' => $admin['id'] ?? null,
                'p_actual_size_bytes' => $actualSize,
                'p_sha256' => null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A Unity app release could not be published.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $finalized = ['data' => [], 'error' => 'unavailable'];
        }

        $published = $finalized['data'][0] ?? null;
        if (($finalized['error'] ?? null) !== null || !is_array($published)) {
            return $this->mutationResponse(
                $request,
                'The upload finished, but the version could not be published. Check the file and try again.',
                422,
            );
        }

        $this->supabase->audit($admin, 'unity_release.published', 'unity_app_release', $releaseId, [
            'version' => $published['version'] ?? null,
            'size_bytes' => $actualSize,
        ]);

        return $this->mutationResponse(
            $request,
            'The new game version is now available.',
            200,
            ['release' => $published],
        );
    }

    public function fail(Request $request, string $releaseId)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $admin = session('supabase_user', []);
        $reason = trim((string) ($validated['reason'] ?? 'Upload interrupted.'));
        $failed = $this->markFailed($releaseId, $admin, $reason ?: 'Upload interrupted.');

        return $this->mutationResponse(
            $request,
            $failed ? 'The interrupted upload was discarded.' : 'The upload status could not be updated.',
            $failed ? 200 : 422,
        );
    }

    public function activate(string $releaseId)
    {
        $admin = session('supabase_user', []);
        try {
            $result = $this->supabase->adminRpcResult('activate_unity_app_release', [
                'p_release_id' => $releaseId,
                'p_admin_id' => $admin['id'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A Unity app release could not be activated.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $release = $result['data'][0] ?? null;
        if (($result['error'] ?? null) !== null || !is_array($release)) {
            return back()->with('error', 'That game version could not be made current.');
        }

        $this->supabase->audit($admin, 'unity_release.activated', 'unity_app_release', $releaseId, [
            'version' => $release['version'] ?? null,
        ]);

        return redirect()->route('admin.app-releases.index')
            ->with('success', 'Game version ' . ($release['version'] ?? '') . ' is now current.');
    }

    public function download(string $releaseId)
    {
        $release = $this->release($releaseId, 'ready');
        if ($release === null) {
            return redirect()->route('admin.app-releases.index')
                ->with('error', 'That game version is not available for download.');
        }

        try {
            $signed = $this->supabase->createStorageSignedDownload(
                (string) ($release['storage_bucket'] ?? config('mathverse.app_releases.bucket', 'unity-releases')),
                (string) $release['storage_path'],
                (string) $release['download_name'],
                (int) config('mathverse.app_releases.signed_download_ttl', 300),
            );
        } catch (\Throwable $exception) {
            Log::warning('An administrator Unity app download could not be signed.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $signed = ['url' => null, 'error' => 'unavailable'];
        }

        if (($signed['error'] ?? null) !== null || !is_string($signed['url'] ?? null)) {
            return redirect()->route('admin.app-releases.index')
                ->with('error', 'The download link could not be created. Please try again.');
        }

        return redirect()->away($signed['url'])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function release(string $releaseId, string $status): ?array
    {
        try {
            $result = $this->supabase->adminSelectResult(self::RELEASE_TABLE, '*', [
                'id' => $releaseId,
                'status' => $status,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $release = $result['data'][0] ?? null;

        return ($result['error'] ?? null) === null && is_array($release) ? $release : null;
    }

    private function markFailed(string $releaseId, array $admin, string $reason): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $releaseId) !== 1) {
            return false;
        }

        try {
            $result = $this->supabase->adminRpcResult('fail_unity_app_release', [
                'p_release_id' => $releaseId,
                'p_admin_id' => $admin['id'] ?? null,
                'p_failure_reason' => mb_substr($reason, 0, 500),
            ]);
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

        return $status === 404
            || str_contains($error, 'pgrst202')
            || str_contains($error, 'could not find the function')
            || (str_contains($error, 'begin_unity_app_release')
                && str_contains($error, 'does not exist'));
    }

    private function mutationResponse(
        Request $request,
        string $message,
        int $status,
        array $payload = [],
    ) {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $payload, $status);
        }

        return redirect()->route('admin.app-releases.index')->with(
            $status >= 400 ? 'error' : 'success',
            $message,
        );
    }
}
