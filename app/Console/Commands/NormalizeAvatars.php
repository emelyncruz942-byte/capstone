<?php

namespace App\Console\Commands;

use App\Services\SupabaseService;
use App\Support\SquareAvatarImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class NormalizeAvatars extends Command
{
    private const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    protected $signature = 'avatars:normalize {--apply : Upload normalized copies and conditionally update profile URLs} {--limit=100 : Maximum existing photographs to inspect (1-1000)}';

    protected $description = 'Normalize existing profile photos while preserving originals; dry-run by default';

    public function handle(SupabaseService $supabase): int
    {
        if (!config('services.supabase.url') || !config('services.supabase.service_key')) {
            $this->error('The existing server Supabase configuration is required. No photos were changed.');
            return self::FAILURE;
        }
        if (!extension_loaded('gd') || !extension_loaded('exif')) {
            $this->error('PHP GD and EXIF are required. No photos were changed.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 1000.');
            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $counts = ['inspected' => 0, 'ready' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 0];
        $manifest = null;
        try {
            if ($apply) $manifest = $this->openManifest();
            for ($offset = 0; $offset < $limit; $offset += 100) {
                $page = $supabase->adminSelectPage('profiles', 'id,avatar_url', [
                    'avatar_url' => ['operator' => 'not.is', 'value' => 'null'], 'order' => 'id.asc',
                ], min(100, $limit - $offset), $offset);
                if ($page['error'] !== null) throw new \RuntimeException('Could not read profiles.');
                foreach ($page['data'] as $profile) {
                    $counts['inspected']++;
                    $temporary = null;
                    $record = ['id' => $profile['id'], 'before' => $profile['avatar_url'], 'at' => gmdate(DATE_ATOM)];
                    try {
                        if (!$this->safeSource($record['before'])) throw new \RuntimeException('Unsupported source.');
                        $temporary = $this->download($record['before']);
                        $image = @getimagesize($temporary);
                        $mime = (string) ($image['mime'] ?? '');
                        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new \RuntimeException('Invalid image.');
                        if ($mime === 'image/jpeg' && $image[0] === $image[1] && $image[0] <= SquareAvatarImage::MAX_EDGE
                            && filesize($temporary) <= SquareAvatarImage::MAX_COMPRESSED_BYTES) {
                            $counts['unchanged']++;
                            continue;
                        }
                        $encoded = SquareAvatarImage::encode($temporary, $mime, 'image/jpeg');
                        if ($encoded === null) throw new \RuntimeException('Could not encode image.');
                        $counts['ready']++;
                        if (!$apply) continue;
                        // Record the original before any upload. Recovery files stay
                        // outside public/ and are never printed to application logs.
                        $this->record($manifest, $record + ['status' => 'prepared']);
                        $upload = new UploadedFile($temporary, 'existing-avatar', $mime, UPLOAD_ERR_OK, true);
                        $url = $supabase->uploadAvatar($record['id'], $upload);
                        if ($url === null) throw new \RuntimeException('Upload failed.');
                        $record['after'] = $url;
                        $this->record($manifest, $record + ['status' => 'uploaded']);
                        $updated = $supabase->adminUpdate('profiles', ['avatar_url' => $url], [
                            'id' => $record['id'], 'avatar_url' => $record['before'],
                        ]);
                        if (($updated[0]['avatar_url'] ?? null) === $url) {
                            $counts['updated']++;
                            $this->record($manifest, $record + ['status' => 'updated']);
                        } else {
                            // An empty conditional PATCH must never be retried without
                            // its original-URL filter, even when a user changes a photo.
                            $counts['conflicts']++;
                            $this->record($manifest, $record + ['status' => 'not_confirmed']);
                        }
                    } catch (\Throwable) {
                        $counts['failed']++;
                        if ($manifest !== null) $this->record($manifest, $record + ['status' => 'failed']);
                    } finally {
                        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
                    }
                }
                if (count($page['data']) < min(100, $limit - $offset)) break;
            }
        } catch (\Throwable) {
            $counts['failed']++;
        } finally {
            if (is_resource($manifest)) fclose($manifest);
        }
        $this->info(($apply ? 'Apply' : 'Dry run').': '.implode('; ', array_map(
            fn (string $name, int $count): string => "$name $count", array_keys($counts), $counts
        )).'.');
        if ($apply) $this->line('Original objects are preserved. The private recovery manifest is in storage/app/private/avatar-normalization.');
        if ($counts['failed'] || $counts['conflicts']) {
            $this->error('Some photographs were not updated. Resolve failures or concurrent changes before retrying.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    private function safeSource(string $url): bool
    {
        $base = parse_url(rtrim((string) config('services.supabase.url'), '/'));
        $source = parse_url($url);
        if (!is_array($base) || !is_array($source) || ($base['scheme'] ?? '') !== 'https'
            || ($source['scheme'] ?? '') !== 'https' || ($source['host'] ?? null) !== ($base['host'] ?? null)
            || ($source['port'] ?? 443) !== ($base['port'] ?? 443)
            || isset($source['user']) || isset($source['pass']) || isset($source['query']) || isset($source['fragment'])) return false;
        $prefix = rtrim($base['path'] ?? '', '/').'/storage/v1/object/public/avatars/';
        $path = rawurldecode($source['path'] ?? '');
        return str_starts_with($path, $prefix) && !str_contains($path, '\\')
            && !preg_match('/[\x00-\x1f]/', $path)
            && !array_intersect(['.', '..'], explode('/', $path));
    }

    private function download(string $url): string
    {
        $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])->get($url);
        if (!$response->successful() || (int) $response->header('Content-Length') > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException('Image download failed or exceeds the source limit.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'mv-avatar-');
        if ($temporary === false) throw new \RuntimeException('Could not open a temporary image.');
        $input = $response->toPsrResponse()->getBody();
        $output = fopen($temporary, 'wb');
        $bytes = 0;
        try {
            if ($output === false) throw new \RuntimeException('Could not write a temporary image.');
            while (!$input->eof()) {
                $chunk = $input->read(8192);
                if ($chunk === '' && !$input->eof()) throw new \RuntimeException('Image stream stalled.');
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_SOURCE_BYTES) throw new \RuntimeException('Image exceeds the source limit.');
                if (fwrite($output, $chunk) !== strlen($chunk)) throw new \RuntimeException('Temporary image write failed.');
            }
            if ($bytes < 1) throw new \RuntimeException('Empty image.');
        } catch (\Throwable $error) {
            if (is_resource($output)) fclose($output);
            @unlink($temporary);
            throw $error;
        } finally {
            $input->close();
        }
        fclose($output);
        return $temporary;
    }

    private function openManifest()
    {
        $directory = storage_path('app/private/avatar-normalization');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Could not create recovery directory.');
        $path = $directory.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.jsonl';
        $file = fopen($path, 'x+b');
        if ($file === false) throw new \RuntimeException('Could not open recovery manifest.');
        @chmod($path, 0600);
        return $file;
    }

    private function record($manifest, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($manifest, $line) !== strlen($line) || !fflush($manifest)) throw new \RuntimeException('Recovery manifest write failed.');
    }
}
