<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id(); // surrogate PK so Eloquent updateOrCreate issues safe UPDATEs
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('is_granted')->default(false);
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
