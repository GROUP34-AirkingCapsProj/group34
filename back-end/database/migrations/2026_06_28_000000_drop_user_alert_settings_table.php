<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the standalone `user_alert_settings` table with a single
 * `users.alerts_last_seen_at` column. The table only ever held one timestamp
 * per user (the last time they clicked "mark all read" on the alerts bell),
 * which is user-scoped state that belongs on the user record. Existing
 * non-null read-state is backfilled before the table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'alerts_last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('alerts_last_seen_at')->nullable()->after('otp_expires_at');
            });
        }

        if (Schema::hasTable('user_alert_settings')) {
            DB::statement('
                UPDATE users u
                JOIN user_alert_settings s ON s.user_id = u.user_id
                SET u.alerts_last_seen_at = s.pending_approvals_last_seen_at
                WHERE s.pending_approvals_last_seen_at IS NOT NULL
            ');

            Schema::dropIfExists('user_alert_settings');
        }
    }

    public function down(): void
    {
        Schema::create('user_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamp('pending_approvals_last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::statement('
            INSERT INTO user_alert_settings (user_id, pending_approvals_last_seen_at, created_at, updated_at)
            SELECT user_id, alerts_last_seen_at, NOW(), NOW()
            FROM users
            WHERE alerts_last_seen_at IS NOT NULL
        ');

        if (Schema::hasColumn('users', 'alerts_last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('alerts_last_seen_at');
            });
        }
    }
};
