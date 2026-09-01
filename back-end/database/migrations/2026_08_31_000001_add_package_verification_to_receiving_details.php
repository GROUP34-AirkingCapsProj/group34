<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the clerk actually verified when the boxes were opened.
 *
 * quantity_amount keeps its meaning — pieces actually posted. The shortfall is
 * pieces_expected minus quantity_amount, which is what finally distinguishes
 * "four boxes never arrived" from "six boxes arrived under-filled". The PO alone
 * cannot tell those apart, and they are different conversations with a supplier.
 *
 * pieces_expected is STORED, not recomputed. If a product's pieces_per_package
 * is later edited — a supplier moving from 50 to 40 per box — recomputing would
 * silently rewrite every historical expectation and erase past discrepancies.
 *
 * Both nullable: they only mean something for package-counted materials. A
 * piece-counted line has no package to verify, and appliance lines have none
 * either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('receiving_details', 'packages_received')) {
            return;
        }

        Schema::table('receiving_details', function (Blueprint $table) {
            $table->integer('packages_received')->nullable()->after('quantity_amount');
            $table->integer('pieces_expected')->nullable()->after('packages_received');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropColumn(['packages_received', 'pieces_expected']);
        });
    }
};
