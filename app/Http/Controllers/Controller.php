<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    protected const MAX_AVATAR_SIZE_BYTES = 2 * 1024 * 1024;

    private const AVATAR_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    protected function rejectInvalidAvatar(Request $request, ?string $redirectTo = null): ?RedirectResponse
    {
        $avatar = $request->file('avatar');

        if ($avatar === null) {
            return null;
        }

        $size = $avatar->getSize();

        if (!$avatar->isValid() || !is_int($size) || $size < 1 || $size > self::MAX_AVATAR_SIZE_BYTES) {
            return $this->avatarError(
                $redirectTo,
                'The selected image must be 2 MB or less.'
            );
        }

        $path = $avatar->getRealPath();
        $image = is_string($path) ? @getimagesize($path) : false;
        $detectedMime = strtolower((string) ($image['mime'] ?? ''));
        $fileMime = strtolower((string) $avatar->getMimeType());
        if ($image === false
            || !in_array($detectedMime, self::AVATAR_MIME_TYPES, true)
            || $fileMime !== $detectedMime
        ) {
            return $this->avatarError(
                $redirectTo,
                'Choose a valid JPEG, PNG, or WebP image.'
            );
        }

        return null;
    }

    /**
     * Write a spreadsheet-safe CSV row. A leading apostrophe prevents cells
     * controlled by users from being interpreted as formulas by Excel or
     * similar spreadsheet applications.
     *
     * @param resource $stream
     */
    protected function writeCsvRow($stream, array $cells): void
    {
        fputcsv($stream, array_map(function (mixed $value): string {
            if ($value === null) {
                return '';
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            $cell = str_replace("\0", '', (string) $value);

            return preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1
                ? "'" . $cell
                : $cell;
        }, $cells));
    }

    protected function safeSearchTerm(mixed $value, int $maxLength = 80): string
    {
        $search = trim(mb_substr((string) $value, 0, max(1, $maxLength)));
        $search = preg_replace("/[^\\p{L}\\p{N}\\s@._'\x{2019}-]+/u", '', $search) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $search) ?? '');
    }

    /**
     * Replace an avatar without leaving an unattached upload behind or
     * allowing a storage outage to become an unhandled server error.
     *
     * @return array{url: ?string, error: ?string}
     */
    protected function replaceProfileAvatar(
        SupabaseService $supabase,
        string $userId,
        UploadedFile $avatar,
        ?string $oldAvatarUrl
    ): array {
        try {
            $avatarUrl = $supabase->uploadAvatar($userId, $avatar);
        } catch (\Throwable $exception) {
            Log::warning('A profile avatar could not be uploaded.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return ['url' => null, 'error' => 'upload'];
        }

        if ($avatarUrl === null) {
            return ['url' => null, 'error' => 'upload'];
        }

        try {
            $avatarUpdated = $supabase->updateProfile($userId, ['avatar_url' => $avatarUrl]);
        } catch (\Throwable $exception) {
            $this->deleteAvatarQuietly($supabase, $avatarUrl, $userId);
            Log::warning('A profile avatar could not be attached.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return ['url' => null, 'error' => 'attach'];
        }

        if (!isset($avatarUpdated[0]['id'])) {
            $this->deleteAvatarQuietly($supabase, $avatarUrl, $userId);

            return ['url' => null, 'error' => 'attach'];
        }

        if (is_string($oldAvatarUrl) && $oldAvatarUrl !== '' && $oldAvatarUrl !== $avatarUrl) {
            $this->deleteAvatarQuietly($supabase, $oldAvatarUrl, $userId);
        }

        return ['url' => $avatarUrl, 'error' => null];
    }

    private function avatarError(?string $redirectTo, string $message): RedirectResponse
    {
        $redirect = $redirectTo === null ? back() : redirect($redirectTo);

        return $redirect->with('image_upload_error', $message);
    }

    private function deleteAvatarQuietly(SupabaseService $supabase, string $avatarUrl, string $userId): void
    {
        try {
            $supabase->deleteAvatarByUrl($avatarUrl, $userId);
        } catch (\Throwable $exception) {
            Log::warning('A profile avatar could not be removed from storage.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);
        }
    }
}
