<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an archived account whose login email, username and recovery
     * address have been retired so a new account can take them.
     *
     * A marker inside the retired values themselves would have worked, but it
     * would make the UI parse strings to decide whether the action is still
     * available, and it would be lost the moment an admin edited the row. This
     * also answers "when were these freed?", which the audit trail can only
     * answer for as long as it is retained.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('identifiers_released_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('identifiers_released_at');
        });
    }
};
