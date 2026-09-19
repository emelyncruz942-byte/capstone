<?php

namespace Tests\Unit;

use App\Support\SupabaseAuthError;
use PHPUnit\Framework\TestCase;

class SupabaseAuthErrorTest extends TestCase
{
    public function test_it_explains_invalid_credentials(): void
    {
        $this->assertSame(
            'The email or password is incorrect.',
            SupabaseAuthError::loginMessage(['code' => 'invalid_credentials'])
        );
    }

    public function test_it_explains_an_unconfirmed_email(): void
    {
        $this->assertSame(
            'Please confirm your email address before signing in.',
            SupabaseAuthError::loginMessage(['msg' => 'Email not confirmed'])
        );
    }

    public function test_it_uses_a_safe_actionable_fallback(): void
    {
        $this->assertSame(
            'We could not sign you in right now. Please check your details and try again.',
            SupabaseAuthError::loginMessage(['message' => 'Unexpected response'])
        );
    }
}
