<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustedHost
{
    private const CANONICAL_URL = 'https://mathmetaverse.space';

    private const LEGACY_HOST = 'mathverse-production-luqbjt.laravel.cloud';

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));

        if (!$this->isAllowed($host)) {
            abort(400, 'Invalid host.');
        }

        if ($host === self::LEGACY_HOST) {
            $target = self::CANONICAL_URL.$request->getPathInfo();
            $query = $request->getQueryString();
            if (is_string($query) && $query !== '') {
                $target .= '?'.$query;
            }

            return redirect()->away($target, 308);
        }

        return $next($request);
    }

    private function isAllowed(string $host): bool
    {
        foreach ($this->allowedHosts() as $allowedHost) {
            if ($host === $allowedHost) {
                return true;
            }

            if (str_starts_with($allowedHost, '*.')
                && str_ends_with($host, substr($allowedHost, 1))
                && $host !== substr($allowedHost, 2)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $configured = config('app.trusted_hosts', []);
        $hosts = [parse_url(self::CANONICAL_URL, PHP_URL_HOST), self::LEGACY_HOST];
        if (is_array($configured)) {
            $hosts = array_merge($hosts, $configured);
        }
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        if (!app()->isProduction()) {
            $hosts = array_merge($hosts, ['localhost', '127.0.0.1', '::1']);
        }

        return array_values(array_unique(array_filter(array_map(
            static function (mixed $value): ?string {
                $candidate = strtolower(rtrim(trim((string) $value), '.'));

                return preg_match('/^(?:\*\.)?[a-z0-9.-]+$|^::1$/', $candidate) === 1
                    ? $candidate
                    : null;
            },
            $hosts
        ))));
    }
}
