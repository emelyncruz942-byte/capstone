<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MobileTextWrappingTest extends TestCase
{
    public function test_audit_event_total_never_splits_the_events_label_on_mobile(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root . '/resources/views/admin/dashboard.blade.php');
        $css = file_get_contents($root . '/public/css/style.css');

        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertStringContainsString('class="audit-event-count ', $view);
        $this->assertStringContainsString('.audit-event-count {', $css);
        $this->assertMatchesRegularExpression(
            '/\.audit-event-count\s*\{[^}]*white-space:\s*nowrap;[^}]*word-break:\s*normal;[^}]*overflow-wrap:\s*normal;/s',
            $css
        );
        $this->assertStringContainsString('.audit-log-heading { align-items: flex-start; flex-direction: column;', $css);
    }
}

