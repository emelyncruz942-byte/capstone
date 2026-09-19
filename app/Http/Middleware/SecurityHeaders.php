<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Exception responses reuse the nonce with which they were rendered.
        $nonce = $request->attributes->get('csp_nonce') ?: base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        /** @var Response $response */
        $response = $next($request);

        $supabaseOrigin = $this->allowedHttpsOrigin((string) config('services.supabase.url'));
        $imageSources = array_filter(["'self'", 'data:', 'blob:', $supabaseOrigin]);
        $scriptSources = ["'self'", "'nonce-{$nonce}'"];
        $connectSources = ["'self'"];
        if (!app()->isProduction() && ($hotOrigin = $this->localViteHotOrigin()) !== null) {
            $scriptSources[] = $hotOrigin;
            $connectSources[] = $hotOrigin;
            $connectSources[] = str_starts_with($hotOrigin, 'https://')
                ? 'wss://' . substr($hotOrigin, 8)
                : 'ws://' . substr($hotOrigin, 7);
        }
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            'connect-src ' . implode(' ', $connectSources),
            "font-src 'self' data:",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "frame-src 'none'",
            'img-src ' . implode(' ', $imageSources),
            "manifest-src 'self'",
            "object-src 'none'",
            'script-src ' . implode(' ', $scriptSources),
            'script-src-elem ' . implode(' ', $scriptSources),
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline'",
            "worker-src 'self' blob:",
        ];
        if (app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set(
            'Referrer-Policy',
            ($request->is('reset-password') || $request->is('auth/confirm'))
                ? 'no-referrer'
                : 'strict-origin-when-cross-origin'
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('X-XSS-Protection', '0');

        if (app()->isProduction() && $this->requestUsesHttps($request)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        $isSensitivePage = $request->hasSession()
            && $request->session()->has('supabase_user');
        $isPublicSensitiveHtml = str_contains($contentType, 'text/html')
            && ($request->is('/') || $request->is('reset-password') || $request->is('auth/confirm'));
        if ($isSensitivePage || $isPublicSensitiveHtml) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    private function localViteHotOrigin(): ?string
    {
        $hotFile = public_path('hot');
        if (!is_file($hotFile) || !is_readable($hotFile)) {
            return null;
        }

        $parts = parse_url(trim((string) file_get_contents($hotFile)));
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? 0);

        if (!in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || $port < 1
            || $port > 65535
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $displayHost = $host === '::1' ? '[::1]' : $host;

        return "{$scheme}://{$displayHost}:{$port}";
    }

    private function allowedHttpsOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || preg_match('/^[a-z0-9.-]+$/i', (string) $parts['host']) !== 1
        ) {
            return null;
        }

        return 'https://' . strtolower((string) $parts['host']);
    }

    private function requestUsesHttps(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME)) === 'https';
    }
}
