<?php

namespace App\Support;

final class SupabaseAuthError
{
    public static function loginMessage(array $response): string
    {
        $details = mb_strtolower(implode(' ', array_filter([
            $response['code'] ?? null,
            $response['error'] ?? null,
            $response['error_code'] ?? null,
            $response['error_description'] ?? null,
            $response['msg'] ?? null,
            $response['message'] ?? null,
        ], fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')));

        if (str_contains($details, 'email_not_confirmed')
            || str_contains($details, 'email not confirmed')) {
            return 'Please confirm your email address before signing in.';
        }

        if (str_contains($details, 'invalid_credentials')
            || str_contains($details, 'invalid login credentials')) {
            return 'The email or password is incorrect.';
        }

        if (str_contains($details, 'over_request_rate_limit')
            || str_contains($details, 'too many request')
            || str_contains($details, 'rate limit')) {
            return 'Too many sign-in attempts. Please wait a moment and try again.';
        }

        if (str_contains($details, 'user_banned')
            || str_contains($details, 'user is banned')) {
            return 'This account is suspended. Contact a MathVerse administrator.';
        }

        if (str_contains($details, 'captcha')) {
            return 'The security check could not be verified. Please refresh and try again.';
        }

        return 'We could not sign you in right now. Please check your details and try again.';
    }

    public static function registrationMessage(array $response): string
    {
        $details = self::details($response);

        if (str_contains($details, 'user_already_exists')
            || str_contains($details, 'already registered')
            || str_contains($details, 'already exists')) {
            return 'An account already exists with that email address.';
        }

        if (str_contains($details, 'weak_password') || str_contains($details, 'password')) {
            return 'Use at least 8 characters with uppercase, lowercase, a number, and a symbol.';
        }

        if (str_contains($details, 'rate limit') || str_contains($details, 'too many')) {
            return 'Too many registration attempts. Please wait before trying again.';
        }

        return 'MathVerse could not create the account. Please try again.';
    }

    private static function details(array $response): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $response['code'] ?? null,
            $response['error'] ?? null,
            $response['error_code'] ?? null,
            $response['error_description'] ?? null,
            $response['msg'] ?? null,
            $response['message'] ?? null,
            $response['data']['code'] ?? null,
            $response['data']['error'] ?? null,
            $response['data']['msg'] ?? null,
            $response['data']['message'] ?? null,
        ], fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')));
    }
}
