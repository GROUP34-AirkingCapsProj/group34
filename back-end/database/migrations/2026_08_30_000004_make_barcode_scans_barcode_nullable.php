<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `barcode_scans` MODIFY `barcode` VARCHAR(191) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE `barcode_scans` SET `barcode` = '' WHERE `barcode` IS NULL");
        DB::statement('ALTER TABLE `barcode_scans` MODIFY `barcode` VARCHAR(191) NOT NULL');
    }
};
