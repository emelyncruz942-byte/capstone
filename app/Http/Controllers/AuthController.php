<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AdminPushService;
use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use App\Support\SupabaseAccessToken;
use App\Support\SupabaseAuthError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        private SupabaseService $supabase,
        private AdminPushService $adminPush,
        private NotificationDeliveryService $notificationDelivery,
    ) {}

    public function showLogin(Request $request)
    {
        $sessionUser = $request->session()->get('supabase_user');
        $sessionRole = is_array($sessionUser)
            && in_array($sessionUser['role'] ?? null, ['student', 'teacher', 'admin'], true)
                ? $sessionUser['role']
                : null;
        if ($sessionUser !== null && $sessionRole === null) {
            $request->session()->forget([
                'supabase_token',
                'supabase_user',
                'supabase_authenticated_at',
            ]);
            $request->session()->regenerate();
        }

        $authAction = (string) $request->query('auth_action', '');

        if ($authAction === 'signup') {
            session()->flash('success', 'Email confirmed successfully. You can now sign in.');
        } elseif ($authAction === 'email_change') {
            session()->flash(
                'success',
                'Email address changed successfully.'
            );
        }

        if ($sessionRole !== null) {
            return $this->redirectByRole($sessionRole);
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:254',
            'password' => 'required|string|max:128',
        ]);

        try {
            $result = $this->supabase->signIn($validated['email'], $validated['password']);
        } catch (\Throwable) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'MathVerse could not reach the sign-in service. Please try again.'
            );
        }

        $accessToken = $this->validatedBearerToken($result['access_token'] ?? null);
        if (isset($result['error']) || $accessToken === null) {
            return back()->withInput($request->only('email'))
                ->with('error', SupabaseAuthError::loginMessage($result));
        }

        $userId = $result['user']['id'] ?? null;
        if (!is_string($userId) || !Str::isUuid($userId)) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'Your credentials were accepted, but MathVerse could not verify your account. Please try again.'
            );
        }

        // Fetch profile to get role
        try {
            $profiles = $this->supabase->adminSelect(
                'profiles',
                'id,role,first_name,last_name,email,avatar_url,grade_level,suspended_at,leaderboard_alias,show_on_leaderboard,auth_sessions_invalid_before',
                ['id' => $userId]
            );
        } catch (\Throwable $exception) {
            Log::warning('Profile lookup after sign-in failed.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return back()->withInput($request->only('email'))->with(
                'error',
                'Your credentials were accepted, but MathVerse could not load your profile. Please try again.'
            );
        }
        $profile  = $profiles[0] ?? null;

        if (!$profile) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Your sign-in succeeded, but your MathVerse profile is unavailable. Contact an administrator.');
        }

        if (!empty($profile['suspended_at'])) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Your account is suspended. Contact an administrator.');
        }

        if ($profile['role'] === 'pending_teacher') {
            return back()->withInput($request->only('email'))
                ->with('error', 'Your teacher application is still waiting for administrator approval.');
        }

        if (!in_array($profile['role'] ?? null, ['student', 'teacher', 'admin'], true)) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'Your account is not authorized to use a dashboard. Contact an administrator.'
            );
        }

        // Rotate the session identifier before attaching authenticated data.
        $request->session()->regenerate();
        $authEmail = $result['user']['email'] ?? null;
        if (!is_string($authEmail) || filter_var($authEmail, FILTER_VALIDATE_EMAIL) === false) {
            $authEmail = $validated['email'];
        }
        $request->session()->forget('supabase_token');
        $request->session()->put([
            'supabase_user'  => array_merge($profile, [
                'email' => mb_strtolower($authEmail),
            ]),
            'supabase_authenticated_at' => $profile['auth_sessions_invalid_before']
                ?? now()->utc()->toIso8601String(),
        ]);

        try {
            $this->supabase->audit($profile, 'user.logged_in', 'profile', $profile['id'], [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Sign-in audit could not be recorded.', [
                'user_id' => $profile['id'] ?? null,
                'exception' => $exception::class,
            ]);
        }

        return $this->redirectByRole($profile['role'])
            ->withCookie(SupabaseAccessToken::cookie($accessToken));
    }

    public function register(Request $request)
    {
        if ($avatarError = $this->rejectInvalidAvatar($request)) {
            return $avatarError;
        }

        $validated = $request->validate([
            'email' => 'required|email|max:254',
            'password' => [
                'required',
                'string',
                'max:128',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'role' => 'required|in:student,pending_teacher',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'grade_level' => 'nullable|required_if:role,student|integer|between:1,6',
        ]);

        $gradeLevel = $validated['role'] === 'student'
            ? (int) $validated['grade_level']
            : null;

        $email = mb_strtolower(trim($validated['email']));

        try {
            $auth = $this->supabase->signUp(
                $email,
                $validated['password'],
                $validated['role'],
                trim($validated['first_name']),
                trim($validated['last_name']),
                $gradeLevel,
                url('/?auth_action=signup')
            );
        } catch (\Throwable $exception) {
            Log::warning('Registration request failed.', [
                'exception' => $exception::class,
            ]);

            return back()->withInput($request->except(['password', 'password_confirmation']))
                ->with('error', 'MathVerse could not create the account. Please try again.');
        }

        if (!($auth['successful'] ?? false)) {
            return back()->withInput($request->except(['password', 'password_confirmation']))
                ->with('error', SupabaseAuthError::registrationMessage($auth));
        }

        // Auth returns the created user even when email confirmation means no
        // session is issued yet. Never guess an account by listing users: if
        // the exact identifier is absent, defer avatar setup until sign-in.
        // GoTrue returns the User itself when confirmation is required, and
        // { user, access_token, ... } when signup also creates a session.
        $createdUser = $auth['data']['user'] ?? $auth['data'] ?? [];
        $createdUserId = is_array($createdUser) ? ($createdUser['id'] ?? null) : null;
        // An obfuscated duplicate-signup response is not a newly created user.
        // Never attach a file or send an application alert for that response.
        if (is_array($createdUser) && array_key_exists('identities', $createdUser)
            && $createdUser['identities'] === []) {
            $createdUserId = null;
        }
        $userId = is_string($createdUserId) && Str::isUuid($createdUserId)
            ? $createdUserId
            : null;

        if (!$userId) {
            Log::warning('Registration succeeded without a usable user identifier.');

            return redirect('/')->with(
                'success',
                'Registered successfully. Check your email to confirm your account. You can add your avatar after signing in.'
            );
        }

        // ── STEP 3: UPLOAD AVATAR
        $avatarRequested = $request->hasFile('avatar');
        $avatarUrl = null;
        if ($avatarRequested) {
            try {
                $avatarUrl = $this->supabase->uploadAvatar($userId, $request->file('avatar'));
            } catch (\Throwable $exception) {
                Log::warning('Avatar upload failed after registration.', [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ]);
            }
        }

        // ── STEP 4: UPDATE PROFILE
        if ($avatarUrl) {
            try {
                $avatarUpdated = $this->supabase->updateProfile($userId, [
                    'avatar_url' => $avatarUrl,
                ]);
                if (!isset($avatarUpdated[0]['id'])) {
                    throw new \RuntimeException('Profile did not accept the uploaded avatar.');
                }
            } catch (\Throwable $exception) {
                Log::warning('Avatar could not be attached to the new profile.', [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ]);

                try {
                    $this->supabase->deleteAvatarByUrl($avatarUrl, $userId);
                } catch (\Throwable $cleanupException) {
                    Log::warning('Unused registration avatar could not be removed.', [
                        'user_id' => $userId,
                        'exception' => $cleanupException::class,
                    ]);
                }
                $avatarUrl = null;
            }
        }

        $applicationEmail = null;
        if ($validated['role'] === 'pending_teacher') {
            $teacherName = trim($validated['first_name'] . ' ' . $validated['last_name'])
                ?: $validated['email'];
            $this->adminPush->sendAfterResponse(
                'Teacher verification requested',
                "{$teacherName} registered and is ready for verification.",
                '/admin/dashboard?section=role-verify',
                "teacher-verification-{$userId}"
            );
            $applicationEmail = $this->notificationDelivery->deliverNotificationEmailNow(
                $userId,
                'teacher_application_received',
                "teacher-application-received:{$userId}",
            );
        }

        $message = $avatarRequested && $avatarUrl === null
            ? 'Registered successfully. Please verify your email. Your avatar can be added after signing in.'
            : 'Registered successfully! Please verify your email.';
        if (is_array($applicationEmail) && !$applicationEmail['sent']) {
            $message .= $applicationEmail['queued']
                ? ' Your teacher application receipt will be retried automatically.'
                : ' Your application was saved, but its receipt email could not be prepared.';
        }

        return redirect('/')->with('success', $message);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate(['email' => 'required|email|max:254']);
        $email = mb_strtolower(trim($validated['email']));

        try {
            $account = $this->supabase->adminSelectResult('profiles', 'id', [
                'email' => $email,
                'limit' => 1,
            ]);
        } catch (\Throwable) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'MathVerse could not verify that email address. Please try again.'
            );
        }

        if (!array_key_exists('error', $account)
            || $account['error'] !== null
            || !is_array($account['data'] ?? null)) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'MathVerse could not verify that email address. Please try again.'
            );
        }

        if ($account['data'] === []) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Reset email sent if that email address is registered to a MathVerse account.',
            ]);
        }

        try {
            $result = $this->supabase->resetPassword(
                $email,
                url('/reset-password')
            );
        } catch (\Throwable $exception) {
            Log::warning('Password recovery request failed.', [
                'exception' => $exception::class,
            ]);

            return back()->withInput($request->only('email'))->with(
                'error',
                'The recovery email could not be sent. Please try again later.'
            );
        }

        if (!($result['successful'] ?? false)) {
            return back()->withInput($request->only('email'))->with(
                'error',
                'The recovery email could not be sent. Please try again later.'
            );
        }

        return back()->with('success', 'Recovery link sent.');
    }

    public function confirmEmail(Request $request)
    {
        $validated = $request->validate([
            'token_hash' => 'required|string|max:2048',
            'type' => 'required|in:email,email_change',
        ]);

        try {
            $result = $this->supabase->verifyEmailToken(
                $validated['token_hash'],
                $validated['type']
            );
        } catch (\Throwable $exception) {
            Log::warning('Email confirmation failed.', [
                'type' => $validated['type'],
                'exception' => $exception::class,
            ]);

            return redirect('/')->with(
                'error',
                'This email confirmation link is invalid or expired.'
            );
        }

        if (!($result['successful'] ?? false)) {
            return redirect('/')->with(
                'error',
                'This email confirmation link is invalid or expired.'
            );
        }

        $message = $validated['type'] === 'email_change'
            ? 'Email address changed successfully.'
            : 'Email confirmed successfully. You can now sign in.';

        $response = redirect('/')->with('success', $message);
        $refreshedAccessToken = $validated['type'] === 'email_change'
            ? $this->validatedBearerToken($result['data']['access_token'] ?? null)
            : null;

        return $refreshedAccessToken === null
            ? $response
            : $response->withCookie(SupabaseAccessToken::cookie($refreshedAccessToken));
    }

    public function logout(Request $request)
    {
        $accessToken = SupabaseAccessToken::from($request);
        if (is_string($accessToken) && $accessToken !== '') {
            try {
                if (!$this->supabase->signOut($accessToken)) {
                    Log::warning('Remote sign-out was not accepted.');
                }
            } catch (\Throwable $exception) {
                // Local logout must still complete when the Auth service is
                // temporarily unavailable.
                Log::warning('Remote sign-out failed.', [
                    'exception' => $exception::class,
                ]);
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->withCookie(SupabaseAccessToken::forgetCookie());
    }

    private function redirectByRole(string $role)
    {
        return match($role) {
            'admin'   => redirect('/admin/dashboard'),
            'teacher' => redirect('/teacher/dashboard'),
            default   => redirect('/student/dashboard'),
        };
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string|max:128',
            'new_password' => [
                'required',
                'string',
                'max:128',
                'confirmed',
                'different:current_password',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);
        $user = session('supabase_user');
        $redirect = $this->securityRedirect($user['role'] ?? 'student');
        try {
            $check = $this->supabase->signIn($user['email'], $validated['current_password']);
        } catch (\Throwable $exception) {
            Log::warning('Password-change verification failed.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return redirect($redirect)->with('error', 'MathVerse could not verify your password. Please try again.');
        }

        $accessToken = $this->validatedBearerToken($check['access_token'] ?? null);
        if ($accessToken === null) {
            return redirect($redirect)->with('error', 'Current password is incorrect.');
        }

        try {
            $result = $this->supabase->updateAuthUser($accessToken, [
                'password' => $validated['new_password'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Password change failed.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return redirect($redirect)->with('error', 'The password could not be changed. Please try again.');
        }
        if (!$result['successful']) {
            return redirect($redirect)->with('error', 'The password could not be changed. Please try again.');
        }

        $request->session()->regenerate();
        $invalidBefore = null;
        try {
            $invalidBefore = $this->supabase->adminSelect(
                'profiles',
                'auth_sessions_invalid_before',
                ['id' => $user['id'], 'limit' => 1]
            )[0]['auth_sessions_invalid_before'] ?? null;
        } catch (\Throwable $exception) {
            Log::warning('The password-change session marker could not be loaded.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);
        }
        $request->session()->forget('supabase_token');
        $request->session()->put(
            'supabase_authenticated_at',
            $invalidBefore ?: now()->utc()->toIso8601String()
        );
        try {
            $this->supabase->audit($user, 'account.password_changed', 'profile', $user['id']);
        } catch (\Throwable $exception) {
            Log::warning('Password change audit could not be recorded.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);
        }

        return redirect($redirect)
            ->with('success', 'Password changed successfully.')
            ->withCookie(SupabaseAccessToken::cookie($accessToken));
    }

    public function changeEmail(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string|max:128',
            'new_email' => 'required|email|max:254|confirmed',
        ]);
        $user = session('supabase_user');
        $redirect = $this->securityRedirect($user['role'] ?? 'student');
        $newEmail = mb_strtolower(trim($validated['new_email']));

        if (mb_strtolower((string) $user['email']) === $newEmail) {
            return redirect($redirect)->with('error', 'Enter a different email address.');
        }

        try {
            $check = $this->supabase->signIn($user['email'], $validated['current_password']);
        } catch (\Throwable $exception) {
            Log::warning('Email change password verification failed.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return redirect($redirect)->with(
                'error',
                'MathVerse could not verify your password. Please try again.'
            );
        }

        $accessToken = $this->validatedBearerToken($check['access_token'] ?? null);
        if ($accessToken === null) {
            return redirect($redirect)->with('error', 'Current password is incorrect.');
        }

        try {
            $result = $this->supabase->updateAuthUser(
                $accessToken,
                ['email' => $newEmail],
                url('/?auth_action=email_change')
            );
        } catch (\Throwable $exception) {
            Log::warning('Email change request failed.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return redirect($redirect)->with(
                'error',
                'The email change could not be started. Please try again.'
            );
        }

        if (!($result['successful'] ?? false)) {
            return redirect($redirect)->with('error', 'The email change could not be started. Please verify the address and try again.');
        }

        $request->session()->regenerate();
        $request->session()->forget('supabase_token');
        try {
            $this->supabase->audit($user, 'account.email_change_requested', 'profile', $user['id'], [
                'new_email' => $newEmail,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Email change audit could not be recorded.', [
                'user_id' => $user['id'] ?? null,
                'exception' => $exception::class,
            ]);
        }

        return redirect($redirect . '&notice=email-change-requested')
            ->with(
                'success',
                'Email change requested. Check your new email address to confirm the change.'
            )
            ->withCookie(SupabaseAccessToken::cookie($accessToken));
    }

    public function updatePassword(Request $request)
    {
        $submittedToken = $request->input('token');
        $submittedTokenType = $request->input('token_type', 'token_hash');
        if (is_string($submittedToken)
            && $submittedToken !== ''
            && strlen($submittedToken) <= 8192
            && in_array($submittedTokenType, ['token_hash', 'access_token'], true)
        ) {
            // Preserve retries in the encrypted server session instead of
            // flashing a recovery credential back into rendered HTML.
            $request->session()->put('password_recovery_token', $submittedToken);
            $request->session()->put('password_recovery_token_type', $submittedTokenType);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'max:128',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'token' => 'nullable|string|max:8192',
            'token_type' => 'nullable|in:token_hash,access_token',
        ]);

        $recoveryToken = $request->session()->get('password_recovery_token');
        if (!is_string($recoveryToken) || $recoveryToken === '') {
            return back()->with('error', 'The reset link is incomplete. Please request a new one.');
        }

        $recoveryTokenType = $request->session()->get('password_recovery_token_type', 'token_hash');
        if ($recoveryTokenType === 'access_token') {
            $accessToken = $this->validatedBearerToken($recoveryToken);
        } else {
            try {
                $verification = $this->supabase->verifyRecoveryToken($recoveryToken);
            } catch (\Throwable $exception) {
                Log::warning('Password recovery token verification failed.', [
                    'exception' => $exception::class,
                ]);

                return back()->with('error', 'MathVerse could not verify the reset link. Please try again.');
            }

            $accessToken = $this->validatedBearerToken($verification['data']['access_token'] ?? null);
            if (!($verification['successful'] ?? false)) {
                $accessToken = null;
            }
        }

        if ($accessToken === null) {
            $request->session()->forget([
                'password_recovery_token',
                'password_recovery_token_type',
            ]);
            return back()->with('error', 'Invalid or expired reset link. Please request a new one.');
        }

        // Verification consumes the one-time link. Never retain it after this
        // point, even if the password update itself later fails.
        $request->session()->forget([
            'password_recovery_token',
            'password_recovery_token_type',
        ]);

        try {
            $result = $this->supabase->updateAuthUser($accessToken, [
                'password' => $validated['password'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Recovered password update failed.', [
                'exception' => $exception::class,
            ]);

            return back()->with('error', 'The password could not be updated. Please request a new reset link.');
        }

        if (!$result['successful']) {
            return back()->with('error', 'The password could not be updated. Please request a new reset link.');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')
            ->with('success', 'Password updated! Please log in.')
            ->withCookie(SupabaseAccessToken::forgetCookie());
    }

    private function securityRedirect(string $role): string
    {
        return match ($role) {
            'admin' => '/admin/dashboard?section=security',
            'teacher' => '/teacher/dashboard?section=security',
            default => '/student/dashboard?section=security',
        };
    }

    private function validatedBearerToken(mixed $token): ?string
    {
        if (!is_string($token)
            || $token === ''
            || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7F]/', $token) === 1
        ) {
            return null;
        }

        return $token;
    }
}
