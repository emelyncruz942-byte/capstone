<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class AdminAuditFilterTest extends TestCase
{
    private function filters(array $query): array
    {
        $reflection = new ReflectionClass(AdminController::class);
        return $reflection->getMethod('auditFilters')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            new Request($query)
        );
    }

    public function test_only_an_absent_stream_filter_defaults_to_security(): void
    {
        $this->assertSame('security', $this->filters([])['category']);
        foreach (['', null, 'all'] as $allStreams) {
            $this->assertSame('', $this->filters(['audit_category' => $allStreams])['category']);
        }
        $this->assertSame('activity', $this->filters(['audit_category' => 'activity'])['category']);
    }

    public function test_search_filters_still_validate_dates_roles_and_outcomes(): void
    {
        $filters = $this->filters([
            'audit_search' => 'Nova', 'audit_actor_role' => 'teacher',
            'audit_outcome' => 'succeeded', 'audit_from' => '2026-09-13',
            'audit_to' => '2026-02-30',
        ]);
        $this->assertSame('Nova', $filters['search']);
        $this->assertSame('teacher', $filters['actor_role']);
        $this->assertSame('succeeded', $filters['outcome']);
        $this->assertSame('2026-09-13', $filters['from']);
        $this->assertSame('', $filters['to']);
    }
}
