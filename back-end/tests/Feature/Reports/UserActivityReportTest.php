<?php

namespace Tests\Feature\Reports;

use App\Models\LoginActivity;
use App\Reports\Definitions\UserActivityReport;
use App\Reports\ReportParameters;

class UserActivityReportTest extends ReportTestCase
{
    private function params(string $from, string $to, array $extra = []): ReportParameters
    {
        return new ReportParameters(array_merge([
            'date_from' => $from,
            'date_to'   => $to,
        ], $extra));
    }

    public function test_successful_and_failed_logins_appear_and_summary_counts_correctly(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        $other = $this->makeUserAt($location, 'admin');

        LoginActivity::create([
            'user_id'           => $admin->user_id,
            'username_attempt'  => $admin->username,
            'success'           => true,
            'ip_address'        => '10.1.1.1',
            'created_at'        => '2026-08-10 09:00:00',
        ]);
        LoginActivity::create([
            'user_id'           => $other->user_id,
            'username_attempt'  => $other->username,
            'success'           => false,
            'failure_reason'    => 'Invalid credentials.',
            'ip_address'        => '10.1.1.2',
            'created_at'        => '2026-08-10 09:05:00',
        ]);

        $dataset = app(UserActivityReport::class)->build(
            $this->params('2026-08-10', '2026-08-10'),
            $admin
        );

        // Isolate to our own fixtures via user_id, since the shared test DB
        // may carry other seeded login_activities rows within this window.
        $rows = collect($dataset->rows)->filter(
            fn ($row) => in_array($row['username_attempt'], [$admin->username, $other->username], true)
        );

        $this->assertCount(2, $rows);

        $successRow = $rows->firstWhere('username_attempt', $admin->username);
        $this->assertSame('Success', $successRow['outcome']);
        $this->assertSame(trim($admin->first_name . ' ' . $admin->last_name), $successRow['user']);

        $failedRow = $rows->firstWhere('username_attempt', $other->username);
        $this->assertSame('Failed', $failedRow['outcome']);
        $this->assertSame('Invalid credentials.', $failedRow['failure_reason']);
    }

    public function test_summary_counts_reflect_a_scoped_query(): void
    {
        // Scope the whole build to just this one user via the user_id filter,
        // so summary counts are meaningful regardless of pre-seeded rows.
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        $target = $this->makeUserAt($location, 'admin');

        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => true,
            'created_at'       => '2026-08-11 08:00:00',
        ]);
        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => false,
            'failure_reason'   => 'Invalid credentials.',
            'created_at'       => '2026-08-11 08:05:00',
        ]);

        $dataset = app(UserActivityReport::class)->build(
            $this->params('2026-08-11', '2026-08-11', ['user_id' => $target->user_id]),
            $admin
        );

        $this->assertSame('2', $dataset->summary['Login attempts']);
        $this->assertSame('1', $dataset->summary['Distinct users']);
        $this->assertSame('1', $dataset->summary['Failed attempts']);
    }

    public function test_it_is_unavailable_to_a_read_scoped_user_but_available_to_an_auditor(): void
    {
        $location = $this->makeLocation();
        $warehouse = $this->makeUserAt($location, 'warehouse_personnel');
        $auditor = $this->makeUserAt($location, 'auditor');

        $this->assertFalse(app(UserActivityReport::class)->availableTo($warehouse));
        $this->assertTrue(app(UserActivityReport::class)->availableTo($auditor));
    }

    /**
     * login_activities has no location column, which is the only reason this
     * report is withheld from anyone. The branch manager reads every location,
     * so there is nothing left to withhold — and reading who logged in is not
     * administration; managing those accounts is, and that stays admin-only.
     */
    public function test_it_is_available_to_the_branch_manager(): void
    {
        $branchManager = $this->makeUserAt($this->makeLocation(), 'branch_manager');

        $this->assertTrue(app(UserActivityReport::class)->availableTo($branchManager));
    }

    public function test_date_range_filtering_excludes_attempts_outside_the_window(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        $target = $this->makeUserAt($location, 'admin');

        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => true,
            'created_at'       => '2026-07-01 08:00:00',
        ]);
        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => true,
            'created_at'       => '2026-08-15 08:00:00',
        ]);

        $dataset = app(UserActivityReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['user_id' => $target->user_id]),
            $admin
        );

        $this->assertCount(1, $dataset->rows);
        $this->assertSame('2026-08-15 08:00:00', $dataset->rows[0]['occurred_at']);
    }

    public function test_outcome_filter_narrows_to_successes_or_failures_only(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        $target = $this->makeUserAt($location, 'admin');

        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => true,
            'created_at'       => '2026-08-12 08:00:00',
        ]);
        LoginActivity::create([
            'user_id'          => $target->user_id,
            'username_attempt' => $target->username,
            'success'          => false,
            'failure_reason'   => 'Invalid credentials.',
            'created_at'       => '2026-08-12 09:00:00',
        ]);

        $successOnly = app(UserActivityReport::class)->build(
            $this->params('2026-08-12', '2026-08-12', ['user_id' => $target->user_id, 'outcome' => 'success']),
            $admin
        );
        $this->assertCount(1, $successOnly->rows);
        $this->assertSame('Success', $successOnly->rows[0]['outcome']);

        $failureOnly = app(UserActivityReport::class)->build(
            $this->params('2026-08-12', '2026-08-12', ['user_id' => $target->user_id, 'outcome' => 'failure']),
            $admin
        );
        $this->assertCount(1, $failureOnly->rows);
        $this->assertSame('Failed', $failureOnly->rows[0]['outcome']);
    }

    public function test_a_failed_attempt_with_a_null_user_id_still_renders_with_the_attempted_username(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        $ghostUsername = 'ghost_' . uniqid();

        LoginActivity::create([
            'user_id'          => null,
            'username_attempt' => $ghostUsername,
            'success'          => false,
            'failure_reason'   => 'Invalid credentials.',
            'ip_address'       => '10.9.9.9',
            'created_at'       => '2026-08-13 10:00:00',
        ]);

        $dataset = app(UserActivityReport::class)->build(
            $this->params('2026-08-13', '2026-08-13'),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('username_attempt', $ghostUsername);
        $this->assertNotNull($row);
        $this->assertSame('Failed', $row['outcome']);
        $this->assertSame('—', $row['user']);
        $this->assertSame('Invalid credentials.', $row['failure_reason']);
    }
}
