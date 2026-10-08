<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's document block, plus the inspection record.
 *
 * The dealer/customer/site fields are filled by the REPORTER at filing — they
 * know who the unit went to. The inspection fields are filled by the INSPECTOR.
 * The signature columns hold names as printed on the hardcopy that gets signed
 * by hand and carried to the admin; this is a record that signing happened, not
 * a digital signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            // Dealer block
            $table->string('dealer_name', 150)->nullable()->after('return_reason');
            $table->string('dealer_phone', 40)->nullable()->after('dealer_name');
            $table->string('dealer_mobile', 40)->nullable()->after('dealer_phone');
            $table->string('dealer_email', 150)->nullable()->after('dealer_mobile');
            $table->string('branch_address', 255)->nullable()->after('dealer_email');

            // Customer block
            $table->string('customer_name', 150)->nullable()->after('branch_address');
            $table->string('customer_contact', 40)->nullable()->after('customer_name');
            $table->string('site_address', 255)->nullable()->after('customer_contact');
            $table->date('purchased_at')->nullable()->after('site_address');
            $table->date('received_at_dor')->nullable()->after('purchased_at');

            // Inspection
            $table->unsignedBigInteger('inspected_by')->nullable()->after('received_at_dor');
            $table->dateTime('inspected_at')->nullable()->after('inspected_by');
            $table->text('inspection_findings')->nullable()->after('inspected_at');
            $table->boolean('checked_by_asc')->default(false)->after('inspection_findings');

            // Signatures — names as printed on the signed hardcopy
            $table->string('signed_by_representative', 150)->nullable()->after('checked_by_asc');
            $table->string('signed_by_branch_manager', 150)->nullable()->after('signed_by_representative');
            $table->date('signed_at')->nullable()->after('signed_by_branch_manager');

            $table->text('rejection_note')->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn([
                'dealer_name', 'dealer_phone', 'dealer_mobile', 'dealer_email', 'branch_address',
                'customer_name', 'customer_contact', 'site_address', 'purchased_at', 'received_at_dor',
                'inspected_by', 'inspected_at', 'inspection_findings', 'checked_by_asc',
                'signed_by_representative', 'signed_by_branch_manager', 'signed_at', 'rejection_note',
            ]);
        });
    }
};
