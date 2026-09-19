<?php

namespace App\Http\Middleware;

use App\Services\SupabaseService;
use App\Support\SupabaseAccessToken;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SupabaseAuth
{
    public function __construct(private SupabaseService $supabase) {}

    public function handle(Request $request, Closure $next, string $role = ''): mixed
    {
        $legacyAccessToken = SupabaseAccessToken::legacySessionToken($request);
        $accessToken = SupabaseAccessToken::from($request);
        if ($accessToken !== null) {
            $request->attributes->set(SupabaseAccessToken::ATTRIBUTE, $accessToken);
        }
        if ($legacyAccessToken !== null) {
            // Cookie-backed Laravel sessions have a strict browser size limit.
            // Move the comparatively large JWT into its own secure cookie.
            $request->session()->forget('supabase_token');
        }

        $user = session('supabase_user');

        if (!$user) {
            return $this->clearAccessToken(
                redirect('/')->with('error', 'Please log in first.')
            );
        }

        try {
            $currentProfile = $this->supabase->adminSelect(
                'profiles',
                'id,role,first_name,last_name,email,avatar_url,grade_level,suspended_at,leaderboard_alias,show_on_leaderboard,auth_sessions_invalid_before',
                ['id' => $user['id']]
            )[0] ?? null;
        } catch (\Throwable $exception) {
            Log::warning('Authenticated profile verification failed.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);
            $this->invalidateSession($request);

            return $this->clearAccessToken(
                redirect('/')->with('error', 'MathVerse could not verify your session. Please sign in again.')
            );
        }

        if (!$currentProfile) {
            $this->invalidateSession($request);
            return $this->clearAccessToken(
                redirect('/')->with('error', 'Your account is no longer available.')
            );
        }

        if (!empty($currentProfile['suspended_at'])) {
            $this->invalidateSession($request);
            return $this->clearAccessToken(
                redirect('/')->with('error', 'Your account is suspended. Contact an administrator.')
            );
        }

        if ($this->sessionPredatesPasswordChange(
            $request->session()->get('supabase_authenticated_at'),
            $currentProfile['auth_sessions_invalid_before'] ?? null
        )) {
            $this->invalidateSession($request);

            return $this->clearAccessToken(
                redirect('/')->with('error', 'Your password changed. Please sign in again.')
            );
        }

        $currentRole = $currentProfile['role'] ?? null;
        if (!is_string($currentRole)
            || !in_array($currentRole, ['student', 'teacher', 'admin'], true)
        ) {
            $this->invalidateSession($request);

            return $this->clearAccessToken(
                redirect('/')->with(
                    'error',
                    'Your account is not authorized to use a dashboard. Contact an administrator.'
                )
            );
        }

        $user = array_merge($user, $currentProfile);
        session(['supabase_user' => $user]);

        if ($role && ($user['role'] ?? '') !== $role) {
            // Redirect to their correct dashboard
            $userRole = $user['role'] ?? '';
            if ($userRole === 'student') {
                return $this->migrateAccessToken($request, redirect('/student/dashboard'), $legacyAccessToken);
            }
            if ($userRole === 'teacher') {
                return $this->migrateAccessToken($request, redirect('/teacher/dashboard'), $legacyAccessToken);
            }
            if ($userRole === 'admin') {
                return $this->migrateAccessToken($request, redirect('/admin/dashboard'), $legacyAccessToken);
            }

            return $this->clearAccessToken(redirect('/')->with('error', 'Access denied.'));
        }

        $pendingTeacherCount = 0;
        $pendingReportCount = 0;
        $notifications = [];
        $unreadNotificationCount = 0;
        $isSeamlessPageRequest = $request->isMethod('GET')
            && $request->header('X-MathVerse-Navigation') === '1';
        $isBackgroundRevalidation = $request->isMethod('GET')
            && $request->header('X-MathVerse-Revalidate') === '1';
        $isNotificationSnapshot = $request->is('notifications/snapshot');
        $isReportDownload = $request->is(
            'student/report/*',
            'teacher/report/*',
            'admin/report/*',
        );
        $isDashboardDocumentRequest = $request->isMethod('GET')
            && !$request->expectsJson()
            && !$isReportDownload;
        $wantsFreshChrome = $isNotificationSnapshot
            || ($isDashboardDocumentRequest
                && (!$isSeamlessPageRequest || $request->header('X-MathVerse-Chrome') === '1'));
        $dashboardChromeFresh = $wantsFreshChrome;
        if (($user['role'] ?? '') === 'admin'
            && $wantsFreshChrome
            && !$isNotificationSnapshot
            && !$request->is('admin/dashboard')
        ) {
            try {
                $pendingTeacherCount = $this->supabase->adminCount('profiles', [
                    'role' => 'pending_teacher',
                ]);
                $pendingReportCount = $this->supabase->adminCount('quiz_reports', [
                    'status' => 'pending',
                ]);
                view()->share([
                    'adminPendingTeacherCount' => $pendingTeacherCount,
                    'adminPendingReportCount' => $pendingReportCount,
                ]);
            } catch (\Throwable $exception) {
                $dashboardChromeFresh = false;
                Log::warning('Non-critical admin navigation counts failed.', [
                    'user_id' => $user['id'] ?? null,
                    'exception' => $exception::class,
                ]);
            }
        }

        // The minute scheduler advances quiz sessions and creates upcoming
        // notifications. Keeping those writes out of page requests avoids
        // repeating the same non-critical work for every open tab.
        if ($wantsFreshChrome) {
            try {
                $notifications = $this->supabase->adminSelect(
                    'notifications',
                    'id,type,title,message,action_url,data,read_at,created_at',
                    [
                        'user_id' => $user['id'],
                        'order' => 'created_at.desc',
                        'limit' => 12,
                    ]
                );
                $unreadNotificationCount = $this->supabase->adminCount('notifications', [
                    'user_id' => $user['id'],
                    'read_at' => ['operator' => 'is', 'value' => 'null'],
                ]);
            } catch (\Throwable $exception) {
                $dashboardChromeFresh = false;
                Log::warning('Non-critical dashboard notifications failed.', [
                    'user_id' => $user['id'] ?? null,
                    'exception' => $exception::class,
                ]);
            }
        }
        view()->share([
            'notifications' => $notifications,
            'unreadNotificationCount' => $unreadNotificationCount,
            'dashboardChromeFresh' => $dashboardChromeFresh,
        ]);
        $request->attributes->set('dashboard_chrome_fresh', $dashboardChromeFresh);

        $response = $next($request);

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($request->isMethod('GET')
            && !$isBackgroundRevalidation
            && $response->getStatusCode() === 200
            && str_contains($contentType, 'text/html')) {
            $route = $request->route();
            $routeName = $route?->getName();
            $routeUri = $route?->uri() ?? ltrim($request->path(), '/');
            try {
                $this->supabase->audit($user, 'page.viewed', 'page', $routeName ?: $routeUri, [
                    // Query strings may carry search terms or other private
                    // context, so page-view audit records retain only the path.
                    'path' => '/' . ltrim(mb_substr($request->path(), 0, 1000), '/'),
                    'route' => $routeName,
                    'status' => $response->getStatusCode(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Page-view audit could not be recorded.', [
                    'user_id' => $user['id'] ?? null,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $this->migrateAccessToken($request, $response, $legacyAccessToken);
    }

    private function invalidateSession(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function migrateAccessToken(
        Request $request,
        Response $response,
        ?string $legacyAccessToken
    ): Response {
        if ($legacyAccessToken !== null
            && !SupabaseAccessToken::isValid($request->cookie(SupabaseAccessToken::COOKIE))
        ) {
            $response->headers->setCookie(SupabaseAccessToken::cookie($legacyAccessToken));
        }

        return $response;
    }

    private function clearAccessToken(Response $response): Response
    {
        $response->headers->setCookie(SupabaseAccessToken::forgetCookie());

        return $response;
    }

    private function sessionPredatesPasswordChange(mixed $authenticatedAt, mixed $invalidBefore): bool
    {
        if (!is_string($invalidBefore) || trim($invalidBefore) === '') {
            return false;
        }

        if (!is_string($authenticatedAt) || trim($authenticatedAt) === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($authenticatedAt, 'UTC')
                ->lt(CarbonImmutable::parse($invalidBefore, 'UTC'));
        } catch (\Throwable) {
            return true;
        }
    }
}
