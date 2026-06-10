<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('permission_id');
            $table->string('feature', 64);
            $table->enum('action', ['create', 'read', 'update', 'archive', 'approve']);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['feature', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
