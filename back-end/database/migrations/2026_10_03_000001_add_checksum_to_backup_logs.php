<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            // SHA-256 is always 64 hex chars, so char() rather than varchar().
            // Nullable because every row written before this migration has no
            // checksum, and because a restore row never has a file of its own.
            $table->char('checksum_sha256', 64)->nullable()->after('file_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropColumn('checksum_sha256');
        });
    }
};
