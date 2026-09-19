<?php

namespace Tests\Unit;

use App\Support\AppDate;
use Tests\TestCase;

class AppDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Manila']);
    }

    public function test_utc_and_offsetless_database_timestamps_display_in_philippine_time(): void
    {
        foreach (['2026-09-12T17:30:00Z', '2026-09-12 17:30:00', '2026-09-13T01:30:00+08:00'] as $value) {
            $this->assertSame('2026-09-13 01:30', AppDate::format($value, 'Y-m-d H:i'));
        }
        $this->assertSame('N/A', AppDate::format('not-a-date', 'Y-m-d'));
        $this->assertNull(AppDate::parse(null));
    }

    public function test_daily_counts_convert_before_bucketing_and_skip_invalid_records(): void
    {
        $counts = AppDate::countsByDay([
            ['created_at' => '2026-09-12T15:59:59Z'],
            ['created_at' => '2026-09-12T16:00:00Z'],
            ['created_at' => '2026-09-13T15:59:59Z'],
            ['created_at' => 'invalid'], null, [],
        ]);
        $this->assertSame(['2026-09-12' => 1, '2026-09-13' => 2], $counts);
    }

    public function test_audit_calendar_range_uses_local_midnight_and_exclusive_next_midnight(): void
    {
        $this->assertSame('2026-09-12T16:00:00+00:00', AppDate::dayBoundaryUtc('2026-09-13'));
        $this->assertSame('2026-09-13T16:00:00+00:00', AppDate::dayBoundaryUtc('2026-09-13', true));
        $this->assertNull(AppDate::dayBoundaryUtc('2026-02-30'));
        $this->assertNull(AppDate::dayBoundaryUtc(''));
    }
}
