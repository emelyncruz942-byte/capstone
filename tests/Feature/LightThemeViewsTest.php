<?php

namespace Tests\Feature;

use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightThemeViewsTest extends TestCase
{
    public static function workspaces(): array
    {
        return [['student'], ['teacher'], ['admin']];
    }

    #[DataProvider('workspaces')]
    public function test_role_pages_keep_the_game_shell_with_a_theme_switch_and_staff_identity_access(string $role): void
    {
        request()->setLaravelSession(app('session')->driver());
        view()->share('errors', new ViewErrorBag());
        $user = ['id' => '11111111-1111-4111-8111-111111111111', 'role' => $role, 'first_name' => 'Beth', 'last_name' => 'Brito', 'show_on_leaderboard' => false];
        $student = ['id' => '22222222-2222-4222-8222-222222222222', 'first_name' => 'Maria', 'last_name' => 'Cruz', 'email' => 'learner@example.test', 'grade_level' => 4, 'show_on_leaderboard' => false, 'avatar_url' => 'http://127.0.0.1:8790/logo-180.png'];
        $data = ['user' => $user, 'notifications' => [], 'unreadNotificationCount' => 0];
        if ($role === 'teacher') {
            $view = 'teacher.classes.show';
            $data += ['class' => ['id' => '33333333-3333-4333-8333-333333333333', 'class_name' => 'Grade 4 Mathematics', 'grade_level' => 4, 'join_code' => 'ABC123'],
                'customization' => ['theme_color' => '#4f46e5', 'icon' => 'calculator', 'banner_pattern' => 'grid'],
                'members' => [['profiles' => $student, 'grade_mismatch' => false], ['profiles' => array_merge($student, ['id' => '44444444-4444-4444-8444-444444444444', 'first_name' => 'Luis', 'last_name' => 'Santos', 'avatar_url' => null]), 'grade_mismatch' => false]],
                'mismatchedMembers' => 0, 'openSessions' => [], 'endedSessions' => [], 'leaderboard' => []];
        } elseif ($role === 'admin') {
            $view = 'recovery.index';
            $data += ['admin' => true, 'trashReady' => true, 'type' => 'class', 'search' => '', 'items' => [], 'page' => 1, 'pages' => 1];
        } else {
            $view = 'app-releases.latest';
            $data += ['releasesReady' => true, 'releases' => [['id' => '55555555-5555-4555-8555-555555555555', 'platform' => 'android', 'version' => '2.0', 'size_bytes' => 120000000, 'release_notes' => 'Explore MathVerse, take on classroom quizzes, and practice with your NPC guides. Includes improved controls and a clearer interface.']]];
        }
        $html = view($view, $data)->render();
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringContainsString('css/modern-light.css', $html);
        $this->assertStringContainsString('js/avatar-cropper.js', $html);
        $this->assertStringContainsString('data-dashboard-role="'.$role.'"', $html);
        $this->assertStringContainsString('class="stars-container"', $html);
        if ($role === 'teacher') {
            $this->assertStringContainsString('Cruz, Maria', $html);
            $this->assertStringContainsString('src="http://127.0.0.1:8790/logo-180.png"', $html);
            $this->assertStringContainsString('data-avatar-name="Maria Cruz"', $html);
        }
        if ($directory = getenv('MATHVERSE_DESIGN_PREVIEW_DIR')) {
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/'.$role.'.html', str_replace('http://localhost', 'http://127.0.0.1:8790', $html));
        }
    }
}
