<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\AdminPushService;
use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use App\Support\SupabaseAccessToken;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthSecurityFlowTest extends TestCase
{
    public function test_legacy_query_reset_links_do_not_expose_or_consume_the_token_on_page_load(): void
    {
        $this->withoutVite();
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldNotReceive('verifyRecoveryToken');
        $supabase->shouldNotReceive('updateAuthUser');

        $this->get('/reset-password?token_hash=synthetic-recovery-credential&type=recovery')
            ->assertOk()
            ->assertSee('js/password-reset.js')
            ->assertDontSee('synthetic-recovery-credential')
            ->assertSee('data-has-recovery-token="false"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_public_login_page_discards_an_unsupported_legacy_session(): void
    {
        $this->withoutVite();
        $this->mock(SupabaseService::class);

        $response = $this->withSession([
            'supabase_token' => 'old-token',
            'supabase_user' => [
                'id' => '11111111-1111-4111-8111-111111111111',
                'role' => 'pending_teacher',
            ],
            'supabase_authenticated_at' => '2026-09-06T08:00:00+00:00',
        ])->get('/');

        $response->assertOk();
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionMissing('supabase_authenticated_at');
    }

    public function test_registration_rejects_a_password_without_every_required_character_type(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldNotReceive('signUp');

        $response = $this->from('/')->post('/register', [
            'email' => 'student@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => 'student',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'grade_level' => 6,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('password');
    }

    public function test_teacher_registration_sends_its_application_receipt_during_the_request(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signUp')
            ->once()
            ->andReturn([
                'successful' => true,
                'data' => ['user' => ['id' => $userId]],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldNotReceive('uploadAvatar');
        $supabase->shouldNotReceive('updateProfile');

        $adminPush = $this->mock(AdminPushService::class);
        $adminPush->shouldReceive('sendAfterResponse')->once();

        $delivery = $this->mock(NotificationDeliveryService::class);
        $delivery->shouldReceive('deliverNotificationEmailNow')
            ->once()
            ->with(
                $userId,
                'teacher_application_received',
                'teacher-application-received:' . $userId,
            )
            ->andReturn(['sent' => true, 'queued' => true]);

        $response = $this->post('/register', [
            'email' => 'Teacher@Example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role' => 'pending_teacher',
            'first_name' => 'Test',
            'last_name' => 'Teacher',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas(
            'success',
            'Registered successfully! Please verify your email.'
        );
    }

    public function test_password_reset_rejects_an_unregistered_email(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->with('profiles', 'id', [
                'email' => 'missing@example.com',
                'limit' => 1,
            ])
            ->andReturn([
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldNotReceive('resetPassword');

        $response = $this->from('/')->post('/forgot-password', [
            'email' => 'Missing@Example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors([
            'email' => 'No MathVerse account is registered with that email address.',
        ]);
    }

    public function test_login_rejects_a_malformed_auth_identity_before_profile_lookup(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signIn')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'user' => ['id' => 'not-a-user-id', 'email' => 'student@example.com'],
            ]);
        $supabase->shouldNotReceive('adminSelect');

        $response = $this->from('/')->post('/login', [
            'email' => 'student@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionHas(
            'error',
            'Your credentials were accepted, but MathVerse could not verify your account. Please try again.'
        );
    }

    public function test_login_rejects_a_suspended_profile_before_creating_a_session(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signIn')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'user' => ['id' => $userId, 'email' => 'student@example.com'],
            ]);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with(
                'profiles',
                'id,role,first_name,last_name,email,avatar_url,grade_level,suspended_at,leaderboard_alias,show_on_leaderboard,auth_sessions_invalid_before',
                ['id' => $userId]
            )
            ->andReturn([[
                'id' => $userId,
                'role' => 'student',
                'suspended_at' => '2026-09-06T08:00:00+00:00',
            ]]);

        $response = $this->from('/')->post('/login', [
            'email' => 'student@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_user');
        $response->assertSessionHas(
            'error',
            'Your account is suspended. Contact an administrator.'
        );
    }

    public function test_successful_login_keeps_the_large_access_token_out_of_the_laravel_session(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signIn')
            ->once()
            ->andReturn([
                'access_token' => 'header.payload.signature',
                'user' => ['id' => $userId, 'email' => 'student@example.com'],
            ]);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->andReturn([[
                'id' => $userId,
                'role' => 'student',
                'first_name' => 'Test',
                'last_name' => 'Student',
                'email' => 'student@example.com',
                'avatar_url' => null,
                'grade_level' => 6,
                'suspended_at' => null,
                'auth_sessions_invalid_before' => null,
            ]]);
        $supabase->shouldReceive('audit')->once()->andReturn(true);

        $response = $this->post('/login', [
            'email' => 'student@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertRedirect('/student/dashboard');
        $response->assertSessionHas('supabase_user.id', $userId);
        $response->assertSessionMissing('supabase_token');
        $this->assertNotNull(collect($response->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === SupabaseAccessToken::COOKIE
        ));
    }

    public function test_logout_revokes_the_remote_auth_session_before_clearing_the_browser_session(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signOut')
            ->once()
            ->with('access-token')
            ->andReturn(true);

        $response = $this->withSession([
            'supabase_token' => 'access-token',
            'supabase_user' => [
                'id' => '11111111-1111-4111-8111-111111111111',
                'role' => 'student',
            ],
        ])->post('/logout');

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionMissing('supabase_user');
    }

    public function test_logout_still_clears_the_browser_session_when_remote_auth_is_unavailable(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signOut')
            ->once()
            ->andThrow(new \RuntimeException('Connection failed'));

        $response = $this->withSession([
            'supabase_token' => 'access-token',
            'supabase_user' => [
                'id' => '11111111-1111-4111-8111-111111111111',
                'role' => 'student',
            ],
        ])->post('/logout');

        $response->assertRedirect('/');
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionMissing('supabase_user');
    }

    public function test_supabase_sign_out_requests_global_session_revocation(): void
    {
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'public-anon-key-for-testing',
            'services.supabase.service_key' => 'private-service-key-for-testing',
        ]);
        Http::fake([
            'https://project.supabase.co/auth/v1/logout*' => Http::response(null, 204),
        ]);

        $service = new SupabaseService();

        $this->assertTrue($service->signOut('header.payload.signature'));
        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'https://project.supabase.co/auth/v1/logout?scope=global'
            && $request->hasHeader('apikey', 'public-anon-key-for-testing')
            && $request->hasHeader('Authorization', 'Bearer header.payload.signature')
        );
    }

    public function test_password_reset_sends_a_link_for_a_registered_email(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->with('profiles', 'id', [
                'email' => 'student@example.com',
                'limit' => 1,
            ])
            ->andReturn([
                'data' => [['id' => 'user-id']],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('resetPassword')
            ->once()
            ->with('student@example.com', url('/reset-password'))
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);

        $response = $this->from('/')->post('/forgot-password', [
            'email' => 'Student@Example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('success', 'Recovery link sent.');
    }

    public function test_password_reset_failure_returns_an_error_instead_of_a_server_error(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('adminSelectResult')
            ->once()
            ->andReturn([
                'data' => [['id' => 'user-id']],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('resetPassword')
            ->once()
            ->andThrow(new \RuntimeException('Connection failed'));

        $response = $this->from('/')->post('/forgot-password', [
            'email' => 'student@example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas(
            'error',
            'The recovery email could not be sent. Please try again later.'
        );
    }

    public function test_password_reset_validation_keeps_the_token_only_in_the_server_session(): void
    {
        $this->withoutVite();
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldNotReceive('verifyRecoveryToken');

        $response = $this->from('/reset-password')->post('/update-password', [
            'token' => 'one-time-recovery-token',
            'password' => 'alllowercase',
            'password_confirmation' => 'alllowercase',
        ]);

        $response->assertRedirect('/reset-password');
        $response->assertSessionHasErrors('password');
        $response->assertSessionHas('password_recovery_token', 'one-time-recovery-token');
        $response->assertSessionHas('password_recovery_token_type', 'token_hash');
        $response->assertSessionMissing('_old_input.token');
    }

    public function test_recovery_accepts_the_verified_access_token_returned_by_a_default_email_link(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldNotReceive('verifyRecoveryToken');
        $supabase->shouldReceive('updateAuthUser')
            ->once()
            ->with('header.payload.signature', ['password' => 'NewPassword2!'])
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);

        $response = $this->post('/update-password', [
            'token' => 'header.payload.signature',
            'token_type' => 'access_token',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('success', 'Password updated! Please log in.');
        $response->assertSessionMissing('password_recovery_token');
        $response->assertSessionMissing('password_recovery_token_type');
    }

    public function test_canonical_email_confirmation_verifies_the_token_hash(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('verifyEmailToken')
            ->once()
            ->with('email-confirmation-token', 'email')
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);

        $response = $this->post('/auth/confirm', [
            'token_hash' => 'email-confirmation-token',
            'type' => 'email',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas(
            'success',
            'Email confirmed successfully. You can now sign in.'
        );
    }

    public function test_successful_recovery_invalidates_the_existing_browser_session(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('verifyRecoveryToken')
            ->once()
            ->with('one-time-recovery-token')
            ->andReturn([
                'successful' => true,
                'data' => ['access_token' => 'recovery-access-token'],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('updateAuthUser')
            ->once()
            ->with('recovery-access-token', ['password' => 'NewPassword2!'])
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);

        $response = $this->withSession([
            'password_recovery_token' => 'one-time-recovery-token',
            'supabase_token' => 'old-access-token',
            'supabase_user' => [
                'id' => '11111111-1111-4111-8111-111111111111',
                'role' => 'student',
            ],
        ])->post('/update-password', [
            'token' => '',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('success', 'Password updated! Please log in.');
        $response->assertSessionMissing('password_recovery_token');
        $response->assertSessionMissing('supabase_token');
        $response->assertSessionMissing('supabase_user');
    }

    public function test_email_change_request_redirects_with_a_durable_toast_notice(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);

        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signIn')
            ->once()
            ->with('old@example.com', 'Current1!')
            ->andReturn(['access_token' => 'fresh-token']);
        $supabase->shouldReceive('updateAuthUser')
            ->once()
            ->with(
                'fresh-token',
                ['email' => 'new@example.com'],
                url('/?auth_action=email_change')
            )
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('audit')->once()->andReturn(true);

        $response = $this->withSession([
            'supabase_token' => 'old-token',
            'supabase_user' => [
                'id' => 'user-id',
                'role' => 'student',
                'email' => 'old@example.com',
            ],
        ])->post('/change-email', [
            'current_password' => 'Current1!',
            'new_email' => 'New@Example.com',
            'new_email_confirmation' => 'New@Example.com',
        ]);

        $response->assertRedirect('/student/dashboard?section=security&notice=email-change-requested');
        $response->assertSessionHas(
            'success',
            'Email change requested. Check your new email address to confirm the change.'
        );
    }

    public function test_email_change_notice_is_rendered_without_relying_on_flash_session_data(): void
    {
        $this->mock(SupabaseService::class);

        $response = $this->get('/?notice=email-change-requested');

        $response->assertOk();
        $response->assertSee('Email change requested. Check your new email address to confirm the change.');
    }

    public function test_signup_confirmation_return_shows_a_success_toast(): void
    {
        $this->mock(SupabaseService::class);

        $response = $this->get('/?auth_action=signup');

        $response->assertOk();
        $response->assertSee('Email confirmed successfully. You can now sign in.');
    }

    public function test_email_change_confirmation_keeps_the_session_and_redirects_with_a_toast(): void
    {
        $this->mock(SupabaseService::class);

        $response = $this->withSession([
            'supabase_token' => 'old-token',
            'supabase_user' => [
                'id' => 'user-id',
                'role' => 'student',
                'email' => 'old@example.com',
            ],
        ])->get('/?auth_action=email_change');

        $response->assertRedirect('/student/dashboard');
        $response->assertSessionHas('supabase_token', 'old-token');
        $response->assertSessionHas('supabase_user');
        $response->assertSessionHas(
            'success',
            'Email address changed successfully.'
        );
    }

    public function test_password_change_stays_successful_when_audit_logging_is_unavailable(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);

        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signIn')
            ->once()
            ->with('student@example.com', 'Current1!')
            ->andReturn(['access_token' => 'fresh-token']);
        $supabase->shouldReceive('updateAuthUser')
            ->once()
            ->with('fresh-token', ['password' => 'NewPassword2!'])
            ->andReturn([
                'successful' => true,
                'data' => [],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldReceive('adminSelect')
            ->once()
            ->with('profiles', 'auth_sessions_invalid_before', [
                'id' => 'user-id',
                'limit' => 1,
            ])
            ->andReturn([[
                'auth_sessions_invalid_before' => '2026-09-06T08:00:00+00:00',
            ]]);
        $supabase->shouldReceive('audit')
            ->once()
            ->andThrow(new \RuntimeException('Audit store unavailable'));

        $response = $this->withSession([
            'supabase_token' => 'old-token',
            'supabase_user' => [
                'id' => 'user-id',
                'role' => 'student',
                'email' => 'student@example.com',
            ],
        ])->post('/change-password', [
            'current_password' => 'Current1!',
            'new_password' => 'NewPassword2!',
            'new_password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertRedirect('/student/dashboard?section=security');
        $response->assertSessionMissing('supabase_token');
        $this->assertNotNull(collect($response->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === SupabaseAccessToken::COOKIE
        ));
        $response->assertSessionHas(
            'supabase_authenticated_at',
            '2026-09-06T08:00:00+00:00'
        );
        $response->assertSessionHas('success', 'Password changed successfully.');
    }

    public function test_password_change_rejects_reusing_the_current_password(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);

        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldNotReceive('signIn');

        $response = $this->withSession([
            'supabase_user' => [
                'id' => 'user-id',
                'role' => 'student',
                'email' => 'student@example.com',
            ],
        ])->from('/student/dashboard?section=security')->post('/change-password', [
            'current_password' => 'Current1!',
            'new_password' => 'Current1!',
            'new_password_confirmation' => 'Current1!',
        ]);

        $response->assertRedirect('/student/dashboard?section=security');
        $response->assertSessionHasErrors('new_password');
    }

    public function test_registration_defers_avatar_setup_without_a_valid_created_user_id(): void
    {
        $supabase = $this->mock(SupabaseService::class);
        $supabase->shouldReceive('signUp')
            ->once()
            ->andReturn([
                'successful' => true,
                'data' => ['user' => ['id' => 'not-a-uuid']],
                'error' => null,
                'status' => 200,
            ]);
        $supabase->shouldNotReceive('uploadAvatar');
        $supabase->shouldNotReceive('updateProfile');

        $response = $this->post('/register', [
            'email' => 'Student@Example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role' => 'student',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'grade_level' => 6,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas(
            'success',
            'Registered successfully. Check your email to confirm your account. You can add your avatar after signing in.'
        );
    }
}
