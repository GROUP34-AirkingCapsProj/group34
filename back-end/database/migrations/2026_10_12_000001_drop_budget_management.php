<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes budget management. Airking buys on payment terms — Net 30 by default,
 * carried on purchase_orders.payment_terms — so a period allocation with
 * committed/spent counters never described how money actually moves. In practice
 * it only blocked PR and PO approval, which is friction with no financial
 * meaning behind it.
 *
 * Drops the two budget tables, the purchase_requests.budget_id link, the
 * budget_verification permission with its grants, and the Budget Management nav
 * bundle.
 *
 * down() restores STRUCTURE ONLY — the rows are gone. The pre-drop data is
 * dumped to backups/budget-management-drop-20261012.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_requests', 'budget_id')) {
            Schema::table('purchase_requests', function (Blueprint $table) {
                $table->dropForeign(['budget_id']);
                $table->dropColumn('budget_id');
            });
        }

        // Child first: budget_transactions.budget_id is a restricting FK.
        Schema::dropIfExists('budget_transactions');
        Schema::dropIfExists('budget_allocations');

        // Permission rows outlive the seeder change — they ship in
        // database/ci-schema.sql — so without this the feature keeps appearing
        // in the permission matrix and any existing grant stays alive.
        $permissionIds = DB::table('permissions')
            ->where('feature', 'budget_verification')
            ->pluck('permission_id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('nav_bundle_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('user_permission_overrides')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        $bundleIds = DB::table('nav_permission_bundles')
            ->where('route_path', '/admin/budget-management')
            ->pluck('bundle_id');

        if ($bundleIds->isNotEmpty()) {
            DB::table('nav_bundle_permissions')->whereIn('bundle_id', $bundleIds)->delete();
            DB::table('nav_permission_bundles')->whereIn('bundle_id', $bundleIds)->delete();
        }
    }

    public function down(): void
    {
        // Shape as of the drop: the verification columns were already removed by
        // 2026_09_30_000004_drop_verification_from_budget_allocations.
        Schema::create('budget_allocations', function (Blueprint $table) {
            $table->bigIncrements('budget_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('period_label', 100);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_budget', 14, 2)->default(0);
            $table->decimal('committed', 14, 2)->default(0);
            $table->decimal('spent', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['location_id', 'category_id', 'period_start'], 'budget_location_category_period_unique');
            $table->foreign('location_id')->references('location_id')->on('locations')->cascadeOnDelete();
            $table->foreign('category_id')->references('category_id')->on('category_lookup')->nullOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->bigIncrements('txn_id');
            $table->unsignedBigInteger('budget_id');
            $table->string('txn_type', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->unsignedBigInteger('po_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('budget_id')->references('budget_id')->on('budget_allocations')->restrictOnDelete();
            $table->foreign('pr_id')->references('pr_id')->on('purchase_requests')->nullOnDelete();
            $table->foreign('po_id')->references('po_id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('performed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index(['budget_id', 'txn_type']);
            $table->index(['pr_id', 'txn_type']);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id')->nullable()->after('location_id');
            $table->foreign('budget_id')->references('budget_id')->on('budget_allocations')->nullOnDelete();
        });
    }
};
