<?php

namespace App\Http\Controllers;

use App\Services\CloudflareR2Service;
use App\Services\SupabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppDownloadController extends Controller
{
    private const RELEASE_TABLE = 'unity_app_releases';

    public function __construct(
        private SupabaseService $supabase,
        private CloudflareR2Service $r2,
    ) {}

    public function index(Request $request)
    {
        $user = session('supabase_user', []);
        $role = (string) ($user['role'] ?? '');
        if ($role === 'admin') {
            return redirect()->route('admin.app-releases.index');
        }
        abort_unless(in_array($role, ['student', 'teacher'], true), 403);

        try {
            $result = $this->supabase->adminSelectResult(
                self::RELEASE_TABLE,
                '*',
                [
                    'status' => 'ready',
                    'is_active' => true,
                    'order' => 'activated_at.desc',
                    'limit' => 2,
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('The current Unity app release could not be loaded.', [
                'exception' => $exception::class,
            ]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $releases = ($result['error'] ?? null) === null ? $result['data'] : [];
        $releasesReady = ($result['error'] ?? null) === null;

        return view('app-releases.latest', compact('user', 'releases', 'releasesReady'));
    }

    public function download(Request $request, string $releaseId)
    {
        $user = session('supabase_user', []);
        $role = (string) ($user['role'] ?? '');
        abort_unless(in_array($role, ['student', 'teacher'], true), 403);

        try {
            $result = $this->supabase->adminSelectResult(self::RELEASE_TABLE, '*', [
                'id' => $releaseId,
                'status' => 'ready',
                'is_active' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A Unity app download could not be prepared.', [
                'release_id' => $releaseId,
                'exception' => $exception::class,
            ]);
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $release = $result['data'][0] ?? null;
        if (($result['error'] ?? null) !== null || ! is_array($release)) {
            return redirect()->route('app-downloads.index')
                ->with('error', 'The current game download is temporarily unavailable.');
        }

        return $this->signedDownload($release, route('app-downloads.index'));
    }

    private function signedDownload(array $release, string $fallback)
    {
        try {
            if (($release['storage_provider'] ?? 'supabase') === 'r2') {
                $url = $this->r2->createSignedDownload(
                    (string) ($release['storage_bucket'] ?? ''),
                    (string) ($release['storage_path'] ?? ''),
                    (string) ($release['download_name'] ?? 'MathVerse-mobile-game.apk'),
                    (int) config('mathverse.app_releases.r2.signed_download_ttl', 300),
                );
                $signed = ['url' => $url, 'error' => null];
            } else {
                $signed = $this->supabase->createStorageSignedDownload(
                    (string) ($release['storage_bucket'] ?? config('mathverse.app_releases.bucket', 'unity-releases')),
                    (string) ($release['storage_path'] ?? ''),
                    (string) ($release['download_name'] ?? 'MathVerse-mobile-game.apk'),
                    (int) config('mathverse.app_releases.signed_download_ttl', 300),
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('A Unity app signed download link could not be created.', [
                'release_id' => $release['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $signed = ['url' => null, 'error' => 'unavailable'];
        }

        if (($signed['error'] ?? null) !== null || ! is_string($signed['url'] ?? null)) {
            return redirect($fallback)
                ->with('error', 'The download link could not be created. Please try again.');
        }

        return redirect()->away($signed['url'])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
