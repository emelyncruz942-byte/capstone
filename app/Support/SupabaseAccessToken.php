<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class SupabaseAccessToken
{
    public const COOKIE = 'mathverse-auth-token';

    public const ATTRIBUTE = 'supabase_access_token';

    public static function from(Request $request): ?string
    {
        $attributeToken = $request->attributes->get(self::ATTRIBUTE);
        if (self::isValid($attributeToken)) {
            return $attributeToken;
        }

        $cookieToken = $request->cookie(self::COOKIE);
        if (self::isValid($cookieToken)) {
            return $cookieToken;
        }

        // Keep sessions created by the previous deployment usable long
        // enough for SupabaseAuth to migrate them to the dedicated cookie.
        $legacyToken = $request->session()->get('supabase_token');

        return self::isValid($legacyToken) ? $legacyToken : null;
    }

    public static function legacySessionToken(Request $request): ?string
    {
        $token = $request->session()->get('supabase_token');

        return self::isValid($token) ? $token : null;
    }

    public static function attach(Request $request, string $token): void
    {
        if (!self::isValid($token)) {
            throw new \InvalidArgumentException('Invalid authentication token.');
        }

        $request->attributes->set(self::ATTRIBUTE, $token);
        $request->session()->forget('supabase_token');
    }

    public static function cookie(string $token): Cookie
    {
        if (!self::isValid($token)) {
            throw new \InvalidArgumentException('Invalid authentication token.');
        }

        return cookie(
            self::COOKIE,
            $token,
            max(1, (int) config('session.lifetime', 120)),
            (string) config('session.path', '/'),
            self::cookieDomain(),
            (bool) config('session.secure', true),
            true,
            false,
            config('session.same_site', 'lax')
        );
    }

    public static function forgetCookie(): Cookie
    {
        return cookie()->forget(
            self::COOKIE,
            (string) config('session.path', '/'),
            self::cookieDomain()
        );
    }

    public static function isValid(mixed $token): bool
    {
        return is_string($token)
            && $token !== ''
            && strlen($token) <= 8192
            && preg_match('/[\x00-\x20\x7F]/', $token) !== 1;
    }

    private static function cookieDomain(): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        $domain = config('session.domain');

        return is_string($domain) && trim($domain) !== '' ? $domain : null;
    }
}
