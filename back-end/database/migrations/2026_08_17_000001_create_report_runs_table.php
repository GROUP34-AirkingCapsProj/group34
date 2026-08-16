<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per generated report.
     *
     * The file itself is the record — a report is a point-in-time snapshot, so
     * `Recent Reports` serves the exact bytes that were produced, never a fresh
     * query wearing an old timestamp. `parameters` is kept so an expired run can
     * offer an explicit re-run rather than silently rebuilding.
     */
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->bigIncrements('report_run_id');
            $table->string('report_key', 60);
            $table->string('title_snapshot', 150);
            $table->string('format', 10);
            $table->json('parameters');
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('file_pruned_at')->nullable();

            $table->index('report_key');
            $table->index('created_at');
            $table->index('expires_at');
            $table->foreign('generated_by')
                  ->references('user_id')->on('users')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
