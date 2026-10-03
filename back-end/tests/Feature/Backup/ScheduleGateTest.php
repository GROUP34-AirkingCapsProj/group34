<?php

namespace Tests\Feature\Backup;

use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The gate used to require an exact H:i match, which silently skipped the whole
 * day whenever the machine was off for that one minute. On this install the PC is
 * frequently off overnight, so "catches up on the next tick" is the behaviour that
 * makes scheduled backups actually happen.
 */
class ScheduleGateTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): BackupService
    {
        return app(BackupService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_disabled_schedule_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => false,
            'time'    => '00:30',
        ]));
    }

    public function test_not_due_before_the_configured_time(): void
    {
        Carbon::setTestNow('2026-10-03 00:29:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_due_exactly_at_the_configured_time(): void
    {
        Carbon::setTestNow('2026-10-03 00:30:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_due_hours_late_when_the_exact_minute_was_missed(): void
    {
        Carbon::setTestNow('2026-10-03 09:14:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_not_due_again_once_today_has_succeeded(): void
    {
        Carbon::setTestNow('2026-10-03 09:14:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled'                 => true,
            'time'                    => '00:30',
            'last_scheduled_run_date' => '2026-10-03',
        ]));
    }

    public function test_due_when_the_last_success_was_yesterday(): void
    {
        Carbon::setTestNow('2026-10-03 00:30:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled'                 => true,
            'time'                    => '00:30',
            'last_scheduled_run_date' => '2026-10-02',
        ]));
    }

    public function test_missing_time_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
        ]));
    }

    public function test_malformed_time_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => 'not-a-time',
        ]));
    }
}
