<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets no longer pass through an auditor: an admin's allocation funds
 * requests immediately. Drops the verification columns added by
 * 2026_07_09_000000_add_verification_to_budgets_and_pos, plus the now-unreachable
 * budget_verification.verify permission and its grants.
 *
 * purchase_orders keeps its verification columns — PO verification stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_status', 'verified_by', 'verified_at', 'verification_note']);
        });

        // The permission row outlives the seeder change — it ships in
        // database/ci-schema.sql — so without this it keeps appearing in the
        // permission matrix and keeps any existing grant alive.
        $permissionId = DB::table('permissions')
            ->where('feature', 'budget_verification')
            ->where('action', 'verify')
            ->value('permission_id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('user_permission_overrides')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('permission_id', $permissionId)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            // 'verified', not 'pending': a rollback must not freeze every budget
            // that has been funding requests since verification was removed.
            $table->string('verification_status', 20)->default('verified')->after('spent');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note')->nullable()->after('verified_at');
            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }
};
