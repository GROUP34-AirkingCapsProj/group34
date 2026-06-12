<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nav_permission_bundles', function (Blueprint $table) {
            $table->id('bundle_id');
            $table->string('name');
            $table->string('route_path')->unique();
            $table->string('sidebar_section');
            $table->string('sidebar_icon')->nullable();
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_permission_bundles');
    }
};
