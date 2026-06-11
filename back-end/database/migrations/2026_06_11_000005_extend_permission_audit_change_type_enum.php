<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE permission_audit_logs MODIFY COLUMN change_type ENUM('role_default','user_override','user_override_clear') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE permission_audit_logs MODIFY COLUMN change_type ENUM('role_default','user_override') NOT NULL");
    }
};
