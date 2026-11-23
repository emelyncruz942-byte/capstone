<?php

namespace Tests\Unit;

use App\Support\UserAvatar;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    public function test_authorized_roster_views_keep_a_private_students_real_identity(): void
    {
        $student = ['first_name' => 'Maria', 'last_name' => 'Cruz', 'show_on_leaderboard' => false, 'avatar_url' => 'https://example.com/maria.png'];
        $visible = UserAvatar::presentation($student);
        $this->assertSame('Maria Cruz', $visible['name']);
        $this->assertSame('MC', $visible['initials']);
        $this->assertSame($student['avatar_url'], $visible['url']);
        $this->assertFalse($visible['anonymous']);
        $html = view('partials.user-avatar', ['avatarUser' => $student])->render();
        $this->assertStringContainsString('https://example.com/maria.png', $html);
        $this->assertStringNotContainsString('Anonymous Student', $html);
    }
    public function test_initials_support_unicode_and_multi_word_names(): void
    {
        $this->assertSame('ÉÑ', UserAvatar::presentation(['first_name' => ' Élodie ', 'last_name' => 'Ñerez'])['initials']);
        $this->assertSame('MC', UserAvatar::presentation(['name' => "Maria   dela\tCruz"])['initials']);
        $this->assertSame('小林', UserAvatar::presentation(['display_name' => '小 林'])['initials']);
        $this->assertSame('U', UserAvatar::presentation([])['initials']);
    }

    public function test_color_stays_with_the_account_when_its_name_or_photo_changes(): void
    {
        $first = UserAvatar::presentation(['id' => 'account-42', 'first_name' => 'Beth']);
        $updated = UserAvatar::presentation(['id' => 'account-42', 'first_name' => 'Elizabeth', 'avatar_url' => 'https://example.com/photo.png']);
        $this->assertSame($first['color'], $updated['color']);
        $this->assertGreaterThanOrEqual(0, $first['color']);
        $this->assertLessThan(6, $first['color']);
    }

    public function test_missing_placeholder_and_unsafe_photo_urls_use_initials(): void
    {
        foreach ([null, '', '/default.png', 'https://example.com/default.png?v=3', '/images/default.png', 'javascript:alert(1)', 'data:image/svg+xml,x', '//other.example/photo.png', 'photo.png', 'https://user:password@example.com/photo.png', "https://example.com\\photo.png"] as $url) {
            $this->assertNull(UserAvatar::photoUrl($url), var_export($url, true));
        }
        $this->assertSame('/uploads/avatar.png', UserAvatar::photoUrl('/uploads/avatar.png'));
        $this->assertSame('https://example.com/photo.png?version=2', UserAvatar::photoUrl('https://example.com/photo.png?version=2'));
    }

    public function test_avatar_markup_escapes_names_and_has_no_external_placeholder_request(): void
    {
        $html = view('partials.user-avatar', ['avatarUser' => ['first_name' => '<script>alert(1)</script>', 'last_name' => 'Cruz'], 'avatarSize' => 44])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('default.png', $html);
        $this->assertStringContainsString('data-avatar-initials', $html);
    }

    public function test_saved_photos_and_unsaved_form_previews_keep_separate_markup(): void
    {
        $person = ['first_name' => 'Beth', 'last_name' => 'Brito', 'avatar_url' => 'https://example.com/saved.png'];
        $header = view('partials.user-avatar', ['avatarUser' => $person, 'avatarCurrent' => true])->render();
        $this->assertStringContainsString('src="https://example.com/saved.png"', $header);
        $this->assertStringContainsString('data-current-user-avatar', $header);
        $this->assertStringNotContainsString('data-avatar-preview', $header);
        $preview = view('partials.user-avatar', ['avatarUser' => ['first_name' => 'Beth'], 'avatarPreview' => true])->render();
        $this->assertStringContainsString('id="avatar-preview" data-avatar-preview', $preview);
        $this->assertStringContainsString('data-avatar-placeholder', $preview);
        $this->assertStringNotContainsString('src=', $preview);
    }

    public function test_peer_leaderboard_avatar_uses_only_the_public_name(): void
    {
        $html = view('partials.user-avatar', ['avatarUser' => ['display_name' => 'Anonymous Student'], 'avatarSize' => 36])->render();
        $this->assertStringContainsString('data-avatar-anonymous', $html);
        $this->assertStringNotContainsString('>AS</span>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $private = UserAvatar::presentation(['is_anonymous' => true, 'first_name' => 'Secret', 'avatar_url' => 'https://example.com/private.png']);
        $this->assertSame('Anonymous Student', $private['name']);
        $this->assertSame('', $private['initials']);
        $this->assertNull($private['url']);
    }
}
