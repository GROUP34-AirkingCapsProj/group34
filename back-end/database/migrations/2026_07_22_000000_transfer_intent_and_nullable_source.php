<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('intent', 16)->default('demand')->after('to_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', fn (Blueprint $t) => $t->dropColumn('intent'));
    }
};
