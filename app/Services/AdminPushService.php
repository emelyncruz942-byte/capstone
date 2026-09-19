<?php

namespace App\Services;

use App\Jobs\SendAdminPush;
use App\Support\SafePath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminPushService
{
    public function sendAfterResponse(string $title, string $body, string $url, string $tag): void
    {
        SendAdminPush::dispatch($title, $body, $url, $tag)
            ->onConnection('deferred');
    }

    public function send(string $title, string $body, string $url, string $tag): bool
    {
        // Keeping this call recipient-free preserves the existing broadcast to
        // every administrator for teacher registrations and quiz reports.
        return $this->dispatch($title, $body, $url, $tag);
    }

    public function sendToUser(
        string $userId,
        string $title,
        string $body,
        string $url,
        string $tag
    ): bool
    {
        return $this->dispatch($title, $body, $url, $tag, [$userId]);
    }

    private function dispatch(
        string $title,
        string $body,
        string $url,
        string $tag,
        ?array $userIds = null
    ): bool
    {
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $anonKey = (string) config('services.supabase.anon_key');
        $publicKey = (string) config('services.web_push.public_key');
        $functionUrl = (string) config('services.web_push.function_url');
        $authSecret = (string) config('services.web_push.auth_secret');

        if ($functionUrl === '' && $supabaseUrl !== '') {
            $functionUrl = $supabaseUrl . '/functions/v1/send-admin-push';
        }

        if ($functionUrl === ''
            || $anonKey === ''
            || $publicKey === ''
            || strlen($authSecret) < 32
            || !$this->functionUrlIsAllowed($functionUrl, $supabaseUrl)
        ) {
            Log::warning('MathVerse browser push is not fully configured.');
            return false;
        }

        try {
            $payload = [
                'title' => mb_substr($title, 0, 100),
                'body' => mb_substr($body, 0, 240),
                'url' => SafePath::normalize($url) ?? '/',
                'tag' => mb_substr($tag, 0, 100),
            ];
            if ($userIds !== null) {
                $payload['user_ids'] = array_values(array_unique($userIds));
            }

            $response = Http::connectTimeout(5)->timeout(12)->withHeaders([
                // The Edge Function has its own service role. Only the public
                // anon key and the narrowly scoped push secret leave Laravel.
                'apikey' => $anonKey,
                'X-MathVerse-Push-Secret' => $authSecret,
                'Content-Type' => 'application/json',
            ])->post($functionUrl, $payload);

            if (!$response->successful()) {
                Log::warning('MathVerse browser push failed.', [
                    'status' => $response->status(),
                ]);
            } else {
                $result = $response->json();
                if (is_array($result) && (int) ($result['failed'] ?? 0) > 0) {
                    Log::warning('One or more MathVerse browser pushes were rejected.', [
                        'sent' => (int) ($result['sent'] ?? 0),
                        'failed' => (int) ($result['failed'] ?? 0),
                        'expired' => (int) ($result['expired'] ?? 0),
                        'total' => (int) ($result['total'] ?? 0),
                    ]);
                }
            }

            $result = $response->json();

            return $response->successful()
                && (!is_array($result) || (int) ($result['failed'] ?? 0) === 0);
        } catch (\Throwable $exception) {
            Log::warning('MathVerse browser push could not be sent.', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function functionUrlIsAllowed(string $functionUrl, string $supabaseUrl): bool
    {
        $function = parse_url($functionUrl);
        $supabase = parse_url($supabaseUrl);
        if (!is_array($function)
            || !is_array($supabase)
            || empty($function['host'])
            || empty($supabase['host'])
            || isset($function['user'])
            || isset($function['pass'])
            || isset($function['query'])
            || isset($function['fragment'])
            || strtolower((string) $function['host']) !== strtolower((string) $supabase['host'])
            || $this->normalizedPort($function) !== $this->normalizedPort($supabase)
            || (string) ($function['path'] ?? '') !== '/functions/v1/send-admin-push'
        ) {
            return false;
        }

        $functionScheme = strtolower((string) ($function['scheme'] ?? ''));
        $supabaseScheme = strtolower((string) ($supabase['scheme'] ?? ''));
        if ($functionScheme !== $supabaseScheme || !in_array($functionScheme, ['http', 'https'], true)) {
            return false;
        }

        return !app()->isProduction() || $functionScheme === 'https';
    }

    private function normalizedPort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
