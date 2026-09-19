<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Keep this after Laravel's trusted-proxy normalization so deployments
        // behind an explicitly configured load balancer validate the public
        // host rather than the proxy's internal Host header.
        $middleware->append(\App\Http\Middleware\TrustedHost::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [\App\Http\Middleware\IncidentContext::class]);
        $middleware->alias([
            'auth.supabase' => \App\Http\Middleware\SupabaseAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(function () {
            return !app()->runningInConsole() && config('mathverse.incidents.enabled')
                ? ['reference_id' => app(\App\Services\IncidentReporter::class)->reference(request())] : [];
        });
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            $status = $response->getStatusCode();
            if (!config('mathverse.incidents.enabled') || ($status < 500 && !in_array($status, [403, 419, 429], true))) {
                return $response;
            }
            $kind = $status >= 500 ? 'error' : ($status === 429 ? 'rate_limited' : 'access_denied');
            $reference = app(\App\Services\IncidentReporter::class)->capture(request(), $kind, $status);
            if ($status >= 500) {
                $message = 'This request could not be completed. Please try again. Reference: '.$reference;
                $response = request()->expectsJson() || request()->header('X-MathVerse-Navigation') === '1'
                    ? response()->json(['message' => $message, 'reference_id' => $reference], $status)
                    : response()->view('errors.incident', compact('reference', 'message'), $status);
            } elseif (request()->expectsJson() && $response instanceof \Illuminate\Http\JsonResponse) {
                $payload = $response->getData(true);
                $payload['reference_id'] = $reference;
                $payload['message'] = ($payload['message'] ?? 'This request could not be completed.').' Reference: '.$reference;
                $response->setData($payload);
            }
            $response->headers->set('X-MathVerse-Reference', $reference);
            $response->headers->set('Cache-Control', 'no-store, private');
            return app(\App\Http\Middleware\SecurityHeaders::class)->handle(request(), fn () => $response);
        });
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'token_hash',
            'token_type',
        ]);
    })->create();
