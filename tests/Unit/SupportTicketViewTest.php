<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SupportTicketViewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_student_teacher_and_admin_navigation_expose_support(): void
    {
        $student = file_get_contents($this->root.'/resources/views/student/partials/sidebar-nav.blade.php');
        $teacher = file_get_contents($this->root.'/resources/views/teacher/partials/sidebar-nav.blade.php');
        $admin = file_get_contents($this->root.'/resources/views/admin/partials/sidebar-nav.blade.php');

        $this->assertStringContainsString("route('support-tickets.index')", $student);
        $this->assertStringContainsString("route('support-tickets.index')", $teacher);
        $this->assertStringContainsString("route('admin.support-tickets.index')", $admin);
    }

    public function test_requester_form_captures_diagnostic_context_without_secrets(): void
    {
        $view = file_get_contents($this->root.'/resources/views/support-tickets/index.blade.php');

        $this->assertStringContainsString("route('support-tickets.store')", $view);
        $this->assertStringContainsString('name="category"', $view);
        $this->assertStringContainsString('name="subject"', $view);
        $this->assertStringContainsString('name="description"', $view);
        $this->assertStringContainsString('name="page_url"', $view);
        $this->assertStringContainsString('name="reference_id"', $view);
        $this->assertStringContainsString('Never include your password, sign-in link, or private codes.', $view);
        $this->assertStringNotContainsString('access token', strtolower($view));
    }

    public function test_admin_update_form_preserves_lock_version(): void
    {
        $view = file_get_contents($this->root.'/resources/views/admin/support-tickets/show.blade.php');

        $this->assertStringContainsString("route('admin.support-tickets.update'", $view);
        $this->assertStringContainsString("@method('PATCH')", $view);
        $this->assertStringContainsString('name="lock_version"', $view);
        $this->assertStringContainsString('name="admin_response"', $view);
    }

    public function test_incident_page_offers_authenticated_requesters_a_prefilled_ticket(): void
    {
        $incident = file_get_contents($this->root.'/resources/views/errors/incident.blade.php');
        $form = file_get_contents($this->root.'/resources/views/support-tickets/index.blade.php');

        $this->assertStringContainsString("['student', 'teacher']", $incident);
        $this->assertStringContainsString('/support-tickets?reference_id={{ urlencode($reference) }}', $incident);
        $this->assertStringContainsString("request()->query('reference_id')", $form);
    }
}
