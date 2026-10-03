<?php

namespace Tests\Feature\Backup;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Mirrors the makeUser() helper in tests/Feature/Audit/AuditTrailFilterTest.php —
     * forceCreate plus an explicit Active status, because the users table has
     * guarded columns and a non-null status expectation.
     */
    private function admin(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'admin']);

        $existing = StatusLookup::where('status_category', 'user')->first();

        $statusId = $existing
            ? (int) $existing->status_id
            : (int) StatusLookup::forceCreate([
                'status_name'     => 'Active',
                'status_category' => 'user',
            ])->status_id;

        return User::forceCreate([
            'first_name'    => 'Backup',
            'last_name'     => 'Admin',
            'email'         => 'bkadmin_' . uniqid() . '@test.local',
            'username'      => 'bkadmin_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    public function test_settings_expose_retention_defaults(): void
    {
        SystemSetting::where('key', SystemSetting::KEY_BACKUP_SCHEDULE)->delete();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/backup/settings');

        $response->assertOk()
            ->assertJsonPath('data.schedule.keep_daily_days', 14)
            ->assertJsonPath('data.schedule.keep_weekly_weeks', 12)
            ->assertJsonPath('data.schedule.keep_monthly_months', 12);
    }

    public function test_retention_values_round_trip(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->putJson('/api/backup/settings', [
            'enabled'             => true,
            'time'                => '00:30',
            'keep_daily_days'     => 7,
            'keep_weekly_weeks'   => 4,
            'keep_monthly_months' => 6,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.keep_daily_days', 7)
            ->assertJsonPath('data.keep_weekly_weeks', 4)
            ->assertJsonPath('data.keep_monthly_months', 6);
    }

    public function test_retention_rejects_zero_and_absurd_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/backup/settings', ['keep_daily_days' => 0])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/backup/settings', ['keep_daily_days' => 4000])
            ->assertStatus(422);
    }

    public function test_an_out_of_range_time_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/backup/settings', ['time' => '99:99'])
            ->assertStatus(422);
    }

    public function test_a_time_with_a_trailing_newline_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/backup/settings', ['time' => "00:30\n"])
            ->assertStatus(422);
    }

    public function test_next_scheduled_run_is_today_when_the_time_is_still_ahead(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-03 06:00:00');

        $response = $this->actingAs($this->admin(), 'sanctum')->putJson('/api/backup/settings', [
            'enabled' => true,
            'time'    => '23:30',
        ]);
        $response->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/backup/settings')
            ->assertOk()
            ->assertJsonPath('data.stats.next_scheduled_run', '2026-10-03T23:30:00+00:00');

        \Illuminate\Support\Carbon::setTestNow();
    }
}
