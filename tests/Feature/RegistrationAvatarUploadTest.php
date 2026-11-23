<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationAvatarUploadTest extends TestCase
{
    private const USER_ID = '11111111-1111-4111-8111-111111111111';

    public static function signupShapes(): array
    {
        return ['confirmation-required user' => [false], 'session response user' => [true]];
    }

    #[DataProvider('signupShapes')]
    public function test_registration_uploads_and_attaches_the_photo_for_both_raw_signup_shapes(bool $nested): void
    {
        config(['services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => str_repeat('a', 32), 'services.supabase.service_key' => str_repeat('b', 32)]);
        $user = ['id' => self::USER_ID, 'email' => 'student@example.test', 'identities' => [['provider' => 'email']]];
        Http::preventStrayRequests();
        Http::fake([
            'project.supabase.co/auth/v1/signup*' => Http::response($nested ? ['user' => $user] : $user),
            'project.supabase.co/storage/v1/object/avatars/*' => Http::response(['Key' => 'uploaded']),
            'project.supabase.co/rest/v1/profiles*' => Http::response([['id' => self::USER_ID]]),
        ]);
        $this->post('/register', $this->registration())->assertRedirect('/')
            ->assertSessionHas('success', 'Registered successfully! Please verify your email.');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/storage/v1/object/avatars/'.self::USER_ID.'_')
            && str_ends_with($request->url(), '.png') && $request->hasHeader('Content-Type', 'image/png'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/v1/profiles?id=eq.'.self::USER_ID)
            && str_starts_with($request['avatar_url'] ?? '', 'https://project.supabase.co/storage/v1/object/public/avatars/'.self::USER_ID.'_'));
    }

    public function test_obfuscated_duplicate_signup_does_not_upload_or_change_an_existing_profile(): void
    {
        config(['services.supabase.url' => 'https://project.supabase.co']);
        Http::preventStrayRequests();
        Http::fake(['project.supabase.co/auth/v1/signup*' => Http::response(['id' => self::USER_ID, 'identities' => []])]);
        $this->post('/register', $this->registration())->assertRedirect('/');
        Http::assertSentCount(1);
    }

    public function test_failed_attachment_cleans_up_only_the_new_photo_and_reports_that_it_was_not_saved(): void
    {
        config(['services.supabase.url' => 'https://project.supabase.co']);
        Http::preventStrayRequests();
        Http::fake([
            'project.supabase.co/auth/v1/signup*' => Http::response(['id' => self::USER_ID]),
            'project.supabase.co/storage/v1/object/avatars/*' => Http::response(['Key' => 'uploaded']),
            'project.supabase.co/rest/v1/profiles*' => Http::response([], 503),
        ]);
        $this->post('/register', $this->registration())->assertRedirect('/')
            ->assertSessionHas('success', 'Registered successfully. Please verify your email. Your avatar can be added after signing in.');
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/storage/v1/object/avatars/'.self::USER_ID.'_'));
    }

    private function registration(): array
    {
        return ['email' => 'student@example.test', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
            'role' => 'student', 'first_name' => 'Test', 'last_name' => 'Student', 'grade_level' => 4,
            'avatar' => UploadedFile::fake()->createWithContent('avatar.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7z8AAAAASUVORK5CYII='
            ))];
    }
}
