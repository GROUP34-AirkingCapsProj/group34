<?php

namespace App\Support;

use Throwable;

/**
 * Driver-agnostic classification of integrity-constraint failures.
 *
 * MySQL reports every integrity violation as SQLSTATE 23000 and distinguishes
 * them only by its own error number (1062 duplicate key, 1451/1452 foreign
 * key). PostgreSQL uses the standard's distinct SQLSTATEs instead:
 *
 *     23502  not_null_violation
 *     23503  foreign_key_violation
 *     23505  unique_violation
 *     23514  check_violation
 *
 * So `$e->getCode() === '23000'` — the obvious thing to write against MySQL —
 * silently stops matching the moment the same code runs on PostgreSQL, and the
 * catch block it guards is skipped. That turns a handled 422 into an uncaught
 * 500, which is exactly what happened to the lookup controllers here.
 *
 * For unique violations prefer catching Laravel's own
 * \Illuminate\Database\UniqueConstraintViolationException, which the framework
 * already raises for both engines. This class covers the cases the framework
 * has no dedicated exception for.
 */
final class DatabaseErrors
{
    /** MySQL reports all integrity violations under this one SQLSTATE. */
    private const MYSQL_INTEGRITY = '23000';

    private const PG_FOREIGN_KEY = '23503';

    private const PG_NOT_NULL = '23502';

    private const PG_CHECK = '23514';

    /**
     * Whether $e is a foreign-key violation.
     *
     * On MySQL this is indistinguishable from any other integrity violation by
     * SQLSTATE alone, so 23000 is accepted — matching the behaviour the code
     * had before it needed to run on two engines.
     */
    public static function isForeignKeyViolation(Throwable $e): bool
    {
        return in_array((string) $e->getCode(), [
            self::MYSQL_INTEGRITY,
            self::PG_FOREIGN_KEY,
        ], true);
    }

    /**
     * Whether $e is any integrity-constraint violation (not null, FK, unique,
     * check). Use when the handler treats every constraint failure alike.
     */
    public static function isIntegrityViolation(Throwable $e): bool
    {
        $code = (string) $e->getCode();

        // Every SQLSTATE in class 23 is an integrity-constraint violation, which
        // covers PostgreSQL's split codes and MySQL's single one alike.
        return str_starts_with($code, '23');
    }

    /**
     * Whether $e is a not-null or check-constraint violation specifically.
     */
    public static function isDataConstraintViolation(Throwable $e): bool
    {
        return in_array((string) $e->getCode(), [
            self::MYSQL_INTEGRITY,
            self::PG_NOT_NULL,
            self::PG_CHECK,
        ], true);
    }
}
