<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable at the DB level so existing rows survive the deploy.
            // Validation requires it when an admin creates a user.
            // Not unique: two employees may legitimately share a household inbox.
            $table->string('recovery_email', 100)->nullable()->after('email');
            $table->boolean('must_change_password')->default(true)->after('password_hash');
        });

        // Existing users keep their passwords. Only accounts created from here
        // on are forced through a change — otherwise this deploy would lock
        // every current user, including admins, out of the system at once.
        DB::table('users')->update(['must_change_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['recovery_email', 'must_change_password']);
        });
    }
};
