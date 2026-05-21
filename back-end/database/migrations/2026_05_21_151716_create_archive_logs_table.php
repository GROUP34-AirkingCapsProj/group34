<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_logs', function (Blueprint $table) {
            $table->bigIncrements('log_id');

            $table->string('triggered_by', 20)->default('manual')
                  ->comment('manual | scheduled | system');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->foreign('triggered_by_user_id')
                  ->references('user_id')->on('users')
                  ->onDelete('set null');

            $table->string('run_type', 20)->default('auto_archive')
                  ->comment('auto_archive | retention_cleanup | preview');

            $table->json('modules_processed')->nullable()
                  ->comment('JSON: {module: {archived: N, skipped: N, errors: N}}');

            $table->integer('total_archived')->default(0);
            $table->integer('total_deleted')->default(0);
            $table->integer('total_skipped')->default(0);

            $table->boolean('is_dry_run')->default(false);
            $table->integer('duration_ms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_logs');
    }
};
