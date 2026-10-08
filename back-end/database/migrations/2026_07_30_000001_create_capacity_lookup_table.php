<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capacity_lookup')) {
            return;
        }

        Schema::create('capacity_lookup', function (Blueprint $table) {
            $table->bigIncrements('capacity_id');

            // Width matches appliance_details.capacity_rating exactly. The rename
            // cascade copies this value into that column, so a wider definition
            // here would truncate silently and desync the item from its lookup.
            $table->string('capacity_value', 20);

            $table->unsignedBigInteger('category_id')->nullable()
                ->comment('NULL = shows for every category');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'capacity_value'], 'capacity_lookup_scope_idx');

            $table->foreign('category_id')
                ->references('category_id')->on('category_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_lookup');
    }
};
