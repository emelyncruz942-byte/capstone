<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardLogoutModalConsistencyTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        parent::setUp();
        $this->views = dirname(__DIR__, 2).'/resources/views';
    }

    #[DataProvider('adminDashboardViews')]
    public function test_admin_pages_reuse_the_dashboard_logout_modal(string $view): void
    {
        $source = file_get_contents($this->views.'/'.$view);

        $this->assertStringContainsString("@include('admin.partials.logout-modal')", $source);
        $this->assertStringNotContainsString('<div id="logoutModal"', $source);
    }

    public static function adminDashboardViews(): array
    {
        return [
            'dashboard' => ['admin/dashboard.blade.php'],
            'game releases' => ['admin/app-releases/index.blade.php'],
            'VR quiz bees' => ['admin/quizzes/index.blade.php'],
            'quiz library' => ['admin/quizzes/library.blade.php'],
            'quiz versions' => ['admin/quizzes/versions.blade.php'],
            'quiz review' => ['admin/quizzes/review.blade.php'],
            'reported quizzes' => ['admin/quiz-reports/index.blade.php'],
            'reported quiz details' => ['admin/quiz-reports/show.blade.php'],
            'system health' => ['admin/system-health.blade.php'],
            'support tickets' => ['admin/support-tickets/index.blade.php'],
            'support ticket details' => ['admin/support-tickets/show.blade.php'],
            'incidents' => ['admin/incidents.blade.php'],
        ];
    }

    public function test_shared_trash_page_uses_the_matching_role_modal(): void
    {
        $source = file_get_contents($this->views.'/recovery/index.blade.php');

        $this->assertStringContainsString("@include(\$admin ? 'admin.partials.logout-modal' : 'teacher.partials.logout-modal')", $source);
        $this->assertStringNotContainsString('<div id="logoutModal"', $source);
    }

    #[DataProvider('teacherReportViews')]
    public function test_teacher_report_pages_reuse_the_teacher_dashboard_logout_modal(string $view): void
    {
        $source = file_get_contents($this->views.'/'.$view);

        $this->assertStringContainsString("@include('teacher.partials.logout-modal')", $source);
        $this->assertStringNotContainsString('<div id="logoutModal"', $source);
    }

    public static function teacherReportViews(): array
    {
        return [
            'reported quizzes' => ['teacher/quiz-reports/index.blade.php'],
            'reported quiz details' => ['teacher/quiz-reports/show.blade.php'],
        ];
    }

    public function test_shared_game_download_uses_role_modal_and_visible_solid_icons(): void
    {
        $source = file_get_contents($this->views.'/app-releases/latest.blade.php');

        $this->assertStringContainsString("'teacher.partials.logout-modal' : 'student.partials.logout-modal'", $source);
        $this->assertStringContainsString("'fa-mobile-screen' : 'fa-robot'", $source);
        $this->assertStringContainsString('role="img" aria-label=', $source);
        $this->assertStringNotContainsString('class="fab ', $source);
    }

    public function test_admin_logout_partial_is_the_canonical_dashboard_modal(): void
    {
        $source = file_get_contents($this->views.'/admin/partials/logout-modal.blade.php');

        $this->assertStringContainsString('id="logoutModal"', $source);
        $this->assertStringContainsString('Are you sure you want to logout?', $source);
        $this->assertStringContainsString('Ending Root Session', $source);
        $this->assertStringContainsString('id="logoutForm"', $source);
    }
}
