<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MarkAllReadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mark_all_read_persists_timestamp_on_users_column(): void
    {
        $uid  = uniqid();
        $user = User::create([
            'first_name'    => 'Alert',
            'last_name'     => 'Reader',
            'username'      => 'alert_reader_' . $uid,
            'email'         => 'alert_reader_' . $uid . '@alerts.test',
            'password_hash' => bcrypt('x'),
            'role_id'       => 1,
        ]);

        $this->assertNull($user->alerts_last_seen_at, 'New user starts with null read-state.');

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/alerts/mark-all-read');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull(
            $resp->json('data.pending_approvals_last_seen_at'),
            'Response keeps the pending_approvals_last_seen_at key for the frontend contract.'
        );

        $this->assertNotNull(
            $user->fresh()->alerts_last_seen_at,
            'mark-all-read must persist the timestamp on users.alerts_last_seen_at.'
        );
    }

    public function test_settings_returns_last_seen_key_from_users_column(): void
    {
        $uid  = uniqid();
        $user = User::create([
            'first_name'    => 'Alert',
            'last_name'     => 'Settings',
            'username'      => 'alert_settings_' . $uid,
            'email'         => 'alert_settings_' . $uid . '@alerts.test',
            'password_hash' => bcrypt('x'),
            'role_id'       => 1,
        ]);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/alerts/settings');

        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'data' => ['badge_count', 'unread_count', 'pending_approvals_last_seen_at'],
        ]);
    }
}
