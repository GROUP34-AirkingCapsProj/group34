<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('issuances', 'external_reference')) {
            Schema::table('issuances', function (Blueprint $table) {
                $table->string('external_reference', 100)
                    ->nullable()
                    ->after('dr_number')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('issuances', 'external_reference')) {
            Schema::table('issuances', function (Blueprint $table) {
                $table->dropIndex(['external_reference']);
                $table->dropColumn('external_reference');
            });
        }
    }
};
