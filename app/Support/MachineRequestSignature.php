<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class MachineRequestSignature
{
    public const TIMESTAMP_HEADER = 'X-MathVerse-Timestamp';

    public const NONCE_HEADER = 'X-MathVerse-Nonce';

    public const SIGNATURE_HEADER = 'X-MathVerse-Signature';

    public const MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    public static function headers(
        string $body,
        string $secret,
        string $scope,
        string $method,
        string $path,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(24));
        $method = strtoupper($method);

        if (! self::validContext($scope, $method, $path)) {
            throw new \InvalidArgumentException('The machine request signing context is invalid.');
        }

        return [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::NONCE_HEADER => $nonce,
            self::SIGNATURE_HEADER => self::signature(
                $body, $secret, $scope, $method, $path, $timestamp, $nonce
            ),
        ];
    }

    public static function verify(Request $request, string $secret, string $scope): bool
    {
        if (strlen($secret) < 32 || preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/', $scope) !== 1) {
            return false;
        }

        $timestampValue = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $nonce = (string) $request->header(self::NONCE_HEADER, '');
        $provided = strtolower((string) $request->header(self::SIGNATURE_HEADER, ''));
        if (preg_match('/^[0-9]{10}$/', $timestampValue) !== 1
            || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $provided) !== 1
        ) {
            return false;
        }

        $timestamp = (int) $timestampValue;
        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }

        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        if (! self::validContext($scope, $method, $path)) {
            return false;
        }

        $expected = self::signature(
            $request->getContent(), $secret, $scope, $method, $path, $timestamp, $nonce
        );
        if (! hash_equals($expected, $provided)) {
            return false;
        }

        // Cache::add is atomic on supported shared production stores. A signed
        // nonce can therefore be accepted only once across all app replicas.
        $replayKey = 'machine-request:'.hash('sha256', $scope."\0".$nonce);
        try {
            return Cache::store((string) config('cache.limiter'))->add(
                $replayKey,
                true,
                self::MAX_CLOCK_SKEW_SECONDS * 2,
            );
        } catch (\Throwable) {
            // Fail closed if replay protection is unavailable. A legitimate
            // caller may safely retry with a fresh nonce.
            return false;
        }
    }

    private static function signature(
        string $body,
        string $secret,
        string $scope,
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
    ): string {
        $canonical = 'v2:'.$scope.':'.$method.':'.$path.':'.$timestamp.':'.$nonce.':'.hash('sha256', $body);

        return hash_hmac('sha256', $canonical, $secret);
    }

    private static function validContext(string $scope, string $method, string $path): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/', $scope) === 1
            && preg_match('/^[A-Z]{3,10}$/', $method) === 1
            && $path !== ''
            && strlen($path) <= 2048
            && str_starts_with($path, '/')
            && ! str_contains($path, "\n")
            && ! str_contains($path, "\r")
            && ! str_contains($path, '?')
            && ! str_contains($path, '#');
    }
}
