<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the ERD's ACTIVITY_LOG table, which was never migrated.
 *
 * App\Models\ActivityLog, the /api/activity-log endpoints and the dashboard's
 * recent-activity feed have all been reading and writing `activity_log` against
 * a table that does not exist. The dashboard and the controllers swallow the
 * resulting "Base table or view not found", so the gap stayed invisible — but
 * the Audit mode of the barcode scanner writes its scan record outside any
 * try/catch, so every audit scan died with a 500 and the UI reported an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log')) {
            return;
        }

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('activity_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity_type', 100);
            $table->string('module', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('module');
            $table->index('activity_type');
            $table->index('created_at');

            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('status_id')->references('status_id')->on('status_lookup')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
