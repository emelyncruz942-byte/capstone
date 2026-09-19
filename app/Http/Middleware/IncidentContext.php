<?php

namespace App\Http\Middleware;

use App\Services\IncidentReporter;
use Closure;
use Illuminate\Http\Request;

class IncidentContext
{
    public function __construct(private IncidentReporter $reporter) {}

    public function handle(Request $request, Closure $next)
    {
        if (!config('mathverse.incidents.enabled')) {
            return $next($request);
        }
        $this->reporter->reference($request);
        $response = $next($request);
        // Runs inside StartSession, before Laravel persists flash feedback.
        if (!$request->isMethod('GET') && $request->hasSession()
            && is_string($error = $request->session()->get('error')) && $error !== ''
            && !str_contains($error, 'Reference: MV-')) {
            $kind = $request->is('login', 'auth/confirm', 'update-password', 'change-password', 'change-email')
                ? 'auth_failure' : 'action_failure';
            $reference = $this->reporter->capture($request, $kind, $response->getStatusCode());
            $request->session()->flash('error', $error.' Reference: '.$reference);
            $response->headers->set('X-MathVerse-Reference', $reference);
        }
        if ($response->getStatusCode() >= 500 || in_array($response->getStatusCode(), [403, 429], true)) {
            $status = $response->getStatusCode();
            $kind = $status >= 500 ? 'error' : ($status === 429 ? 'rate_limited' : 'access_denied');
            $response->headers->set('X-MathVerse-Reference', $this->reporter->capture($request, $kind, $status));
        }
        return $response;
    }
}
