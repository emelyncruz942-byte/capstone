<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The public hostname is part of MathVerse's security boundary. Keep
        // URL generation canonical even while a deployment still has the
        // retired Laravel Cloud hostname in APP_URL.
        if (app()->isProduction()) {
            config([
                'app.url' => (string) config('app.canonical_url'),
                // A host-only cookie survives a custom-domain migration and
                // cannot leak to unrelated subdomains.
                'session.domain' => null,
            ]);
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $appParts = parse_url($appUrl);
        $appScheme = is_array($appParts)
            ? strtolower((string) ($appParts['scheme'] ?? ''))
            : '';
        $appHost = is_array($appParts) ? ($appParts['host'] ?? null) : null;
        if (app()->isProduction()) {
            if ((bool) config('app.debug')) {
                throw new \RuntimeException('Production APP_DEBUG must be false.');
            }

            if (! is_array($appParts)
                || $appScheme !== 'https'
                || ! is_string($appHost)
                || $appHost === ''
                || isset($appParts['user'])
                || isset($appParts['pass'])
                || isset($appParts['query'])
                || isset($appParts['fragment'])
                || ! in_array((string) ($appParts['path'] ?? ''), ['', '/'], true)
            ) {
                throw new \RuntimeException('Production APP_URL must be the HTTPS MathVerse root URL.');
            }

            if (! (bool) config('session.encrypt')
                || ! (bool) config('session.secure')
                || ! (bool) config('session.http_only')
                || ! in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true)
            ) {
                throw new \RuntimeException('Production MathVerse sessions require encryption and secure cookie settings.');
            }

            $limiterStore = (string) config('cache.limiter');
            $limiterDriver = (string) config("cache.stores.{$limiterStore}.driver");
            if (! in_array($limiterDriver, ['database', 'redis', 'memcached', 'dynamodb'], true)) {
                throw new \RuntimeException(
                    'Production CACHE_LIMITER must use a shared database, Redis, Memcached, or DynamoDB store.'
                );
            }
            if ($limiterDriver === 'database') {
                $databaseConnection = (string) (
                    config("cache.stores.{$limiterStore}.connection")
                    ?: config('database.default')
                );
                $databaseDriver = (string) config("database.connections.{$databaseConnection}.driver");
                if ($databaseDriver === '' || $databaseDriver === 'sqlite') {
                    throw new \RuntimeException(
                        'Production database-backed rate limiting requires a shared non-SQLite database connection.'
                    );
                }
            }

            $corsOrigins = config('cors.allowed_origins');
            if (! is_array($corsOrigins) || $corsOrigins === []) {
                throw new \RuntimeException('Production CORS_ALLOWED_ORIGINS must contain at least one exact HTTPS origin.');
            }
            foreach ($corsOrigins as $origin) {
                if (! $this->isExactHttpsOrigin($origin)) {
                    throw new \RuntimeException(
                        'Production CORS_ALLOWED_ORIGINS may contain only exact HTTPS origins without wildcards, paths, or credentials.'
                    );
                }
            }
            if ((array) config('cors.allowed_origins_patterns') !== []
                || (bool) config('cors.supports_credentials')
            ) {
                throw new \RuntimeException('Production CORS cannot use origin patterns or credentialed cross-origin requests.');
            }

            URL::forceRootUrl($appUrl);
            URL::forceScheme('https');
        }

        RateLimiter::for('login', function (Request $request): array {
            $emailKey = hash('sha256', Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(8)->by("login:{$emailKey}:{$request->ip()}"),
                Limit::perHour(30)->by("login-email:{$emailKey}"),
                Limit::perMinute(40)->by("login-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('registration', function (Request $request): array {
            $emailKey = hash('sha256', Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(3)->by("register:{$emailKey}:{$request->ip()}"),
                Limit::perHour(5)->by("register-email:{$emailKey}"),
                Limit::perHour(20)->by("register-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('password-recovery', function (Request $request): array {
            $emailKey = hash('sha256', Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(3)->by("recovery:{$emailKey}:{$request->ip()}"),
                Limit::perHour(5)->by("recovery-email:{$emailKey}"),
                Limit::perHour(20)->by("recovery-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request): array {
            $token = (string) $request->input(
                'token',
                $request->session()->get('password_recovery_token', '')
            );
            if ($token === '') {
                $token = (string) $request->session()->get('password_recovery_token', 'missing');
            }
            $tokenKey = hash('sha256', $token);

            return [
                Limit::perMinute(5)->by("reset:{$tokenKey}:{$request->ip()}"),
                Limit::perHour(20)->by("reset-token:{$tokenKey}"),
                Limit::perHour(30)->by("reset-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('email-confirmation', function (Request $request): array {
            $tokenKey = hash('sha256', (string) $request->input('token_hash', 'missing'));

            return [
                Limit::perMinute(5)->by("email-confirmation:{$tokenKey}:{$request->ip()}"),
                Limit::perHour(20)->by("email-confirmation-token:{$tokenKey}"),
                Limit::perMinute(30)->by("email-confirmation-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('class-join', function (Request $request): array {
            $userId = (string) $request->session()->get('supabase_user.id', 'guest');

            return [
                Limit::perMinute(10)->by("class-join-user:{$userId}"),
                Limit::perHour(60)->by("class-join-hour:{$userId}"),
                Limit::perMinute(30)->by("class-join-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('account-security', function (Request $request): Limit {
            $userId = (string) $request->session()->get('supabase_user.id', $request->ip());

            return Limit::perMinute(5)->by("account-security:{$userId}");
        });

        RateLimiter::for('authenticated', function (Request $request): array {
            $userId = (string) $request->session()->get('supabase_user.id', 'guest');

            return [
                Limit::perMinute(180)->by("authenticated:{$userId}"),
                Limit::perMinute(600)->by("authenticated-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('reports', function (Request $request): array {
            $userId = (string) $request->session()->get('supabase_user.id', 'guest');

            return [
                Limit::perMinute(12)->by("reports:{$userId}"),
                Limit::perMinute(60)->by("reports-ip:{$request->ip()}"),
            ];
        });
    }

    private function isExactHttpsOrigin(mixed $origin): bool
    {
        if (! is_string($origin)
            || $origin === ''
            || strlen($origin) > 2048
            || str_contains($origin, '*')
            || filter_var($origin, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($origin);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
        ) {
            return false;
        }

        $canonical = 'https://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $canonical .= ':'.(int) $parts['port'];
        }

        return rtrim($origin, '/') === $canonical;
    }
}
