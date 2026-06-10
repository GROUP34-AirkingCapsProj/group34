<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('log_id');
            $table->unsignedBigInteger('changed_by');
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedInteger('target_role_id')->nullable();
            $table->unsignedBigInteger('permission_id');
            $table->boolean('old_value')->nullable();
            $table->boolean('new_value');
            $table->enum('change_type', ['role_default', 'user_override']);
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['target_user_id', 'changed_at']);
            $table->index(['target_role_id', 'changed_at']);
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
