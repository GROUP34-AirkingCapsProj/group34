<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('pending')->after('spent');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note')->nullable()->after('verified_at');
            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('pending')->after('verified_by');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->string('verification_note')->nullable()->after('verified_at');
        });

        // Grandfather POs already past Pending so in-flight receiving is not blocked.
        DB::table('purchase_orders')
            ->whereIn('status_id', [8, 10, 11, 12])
            ->update([
                'verification_status' => 'verified',
                'verification_note'   => 'Grandfathered — predates verification workflow',
            ]);
    }

    public function down(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_status', 'verified_by', 'verified_at', 'verification_note']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verified_at', 'verification_note']);
        });
    }
};
