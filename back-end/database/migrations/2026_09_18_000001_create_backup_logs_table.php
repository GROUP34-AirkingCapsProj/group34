<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->bigIncrements('log_id');

            $table->string('action', 10)->default('backup')
                  ->comment('backup | restore');
            $table->string('triggered_by', 20)->default('manual')
                  ->comment('manual | scheduled');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->foreign('triggered_by_user_id')
                  ->references('user_id')->on('users')
                  ->onDelete('set null');

            $table->string('status', 10)->default('success')
                  ->comment('success | failed');

            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            $table->unsignedBigInteger('restored_from_log_id')->nullable();
            $table->foreign('restored_from_log_id')
                  ->references('log_id')->on('backup_logs')
                  ->onDelete('set null');

            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
