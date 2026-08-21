<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * recovery_email stopped being just a delivery target and became the
     * identifier the forgot-password form is keyed on. An account is now
     * looked up BY this address, so a shared inbox would make the lookup
     * ambiguous and silently lock the second account out of self-recovery.
     *
     * This reverses the "Not unique: two employees may legitimately share a
     * household inbox" note on 2026_08_21_000000, which was written while the
     * column was delivery-only.
     */
    public function up(): void
    {
        $dupes = DB::table('users')
            ->selectRaw('LOWER(recovery_email) AS addr, COUNT(*) AS n')
            ->whereNotNull('recovery_email')
            ->where('recovery_email', '!=', '')
            ->groupBy('addr')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('addr')
            ->all();

        if ($dupes !== []) {
            throw new RuntimeException(
                'Cannot make users.recovery_email unique: these addresses are shared by more than one account -- '
                . implode(', ', $dupes)
                . '. Give each account its own recovery address, then re-run this migration.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            // Nullable stays nullable: MySQL permits many NULLs under a unique
            // index, so the accounts with no recovery address on file are
            // unaffected. They simply cannot self-recover.
            $table->unique('recovery_email', 'users_recovery_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_recovery_email_unique');
        });
    }
};
