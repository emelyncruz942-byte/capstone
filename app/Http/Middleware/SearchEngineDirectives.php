<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SearchEngineDirectives
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // The framework health endpoint must remain reachable by monitors, so
        // protect it with a response directive instead of blocking crawlers in
        // robots.txt (which would prevent them from seeing this noindex header).
        if ($request->is('up')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
