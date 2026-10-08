<?php

namespace Tests\Feature\Schema;

use App\Models\PasswordResetOtp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `$table->timestamp('col')` with no default hands MySQL the first TIMESTAMP
 * column in the table, and MySQL quietly attaches DEFAULT CURRENT_TIMESTAMP ON
 * UPDATE CURRENT_TIMESTAMP to it. The ON UPDATE half is the dangerous one: any
 * later write to any other column silently rewrites the timestamp to now.
 *
 * On password_reset_otps.expires_at that broke the forgot-password flow
 * outright — verify() stores the reset token, the save moves expires_at to now,
 * and the reset that follows is refused as expired. It went unnoticed because
 * the developer machine runs its database in Europe/Berlin while Laravel runs
 * in UTC: the rewritten value landed two hours in the FUTURE and read as a
 * still-valid token. On CI, where both clocks are UTC, the same code 422s.
 *
 * Five other columns are guarded here against the same accident, each one a
 * movement, receipt or detection time that an unrelated update would rewrite.
 */
class NoAccidentalTimestampRewriteTest extends TestCase
{
    use DatabaseTransactions;

    /** Every column that must not be rewritten by an unrelated update. */
    public static function columns(): array
    {
        return [
            'password reset otp expiry'   => ['password_reset_otps', 'expires_at'],
            'password reset code expiry'  => ['password_reset_codes', 'expires_at'],
            'batch movement time'         => ['inventory_batch_movements', 'moved_at'],
            'warranty return receipt'     => ['warranty_claim_returns', 'received_at'],
            'warranty movement time'      => ['warranty_inventory_movements', 'moved_at'],
            'drift finding detection time' => ['ledger_drift_findings', 'detected_at'],
        ];
    }

    /**
     * @dataProvider columns
     */
    public function test_the_column_does_not_rewrite_itself_on_update(string $table, string $column): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // ON UPDATE CURRENT_TIMESTAMP is a MySQL/MariaDB column attribute
            // with no PostgreSQL equivalent, so there is nothing there for a
            // column to accidentally acquire. The invariant this test guards
            // cannot be violated on any other engine.
            $this->markTestSkipped('ON UPDATE CURRENT_TIMESTAMP is MySQL-only.');
        }

        $extra = DB::selectOne(
            'select extra from information_schema.columns
              where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        );

        $this->assertNotNull($extra, "{$table}.{$column} is missing.");
        $this->assertStringNotContainsStringIgnoringCase(
            'on update',
            (string) $extra->extra,
            "{$table}.{$column} still rewrites itself whenever the row is updated.",
        );
    }

    /** The behaviour the schema flag causes, stated in the terms the bug appeared in. */
    public function test_storing_the_reset_token_leaves_the_expiry_where_it_was(): void
    {
        $expiresAt = now()->addMinutes(10);

        $row = PasswordResetOtp::create([
            'email'      => 'expiry.test@airking.test',
            'otp_hash'   => Hash::make('123456'),
            'expires_at' => $expiresAt,
        ]);

        // Exactly what ForgotPasswordOtpController::verify() does once the code
        // checks out: burn the OTP and record the token the reset must present.
        $row->forceFill([
            'otp_hash'       => Hash::make(Str::random(32)),
            'verified_token' => Str::random(64),
        ])->save();

        $this->assertSame(
            $expiresAt->format('Y-m-d H:i:s'),
            $row->fresh()->expires_at->format('Y-m-d H:i:s'),
            'Verifying the code moved the expiry, so the reset that follows is refused as expired.',
        );
    }
}
