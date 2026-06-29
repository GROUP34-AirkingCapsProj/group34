<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('changed_by');
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedBigInteger('target_role_id')->nullable();
            $table->unsignedBigInteger('permission_id');
            $table->boolean('old_value')->nullable();
            $table->boolean('new_value')->nullable();
            $table->string('change_type'); // role_default | user_override | user_override_clear
            $table->string('reason')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
