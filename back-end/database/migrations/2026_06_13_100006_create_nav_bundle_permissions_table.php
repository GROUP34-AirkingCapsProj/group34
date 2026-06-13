<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_bundle_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->foreign('bundle_id')
                  ->references('bundle_id')
                  ->on('nav_permission_bundles')
                  ->cascadeOnDelete();

            $table->foreign('permission_id')
                  ->references('permission_id')
                  ->on('permissions')
                  ->cascadeOnDelete();

            $table->unique(['bundle_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_bundle_permissions');
        Schema::dropIfExists('nav_permission_bundles');
    }
};
