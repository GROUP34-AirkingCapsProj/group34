<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class IssuanceStatus
{
    /** @var array<string,int> */
    private static array $cache = [];

    public static function id(string $name): int
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown issuance status: {$name}");
        }

        return self::$cache[$name] = (int) $id;
    }

    public static function draft(): int     { return self::id('Draft'); }
    public static function pending(): int   { return self::id('Pending'); }
    public static function completed(): int { return self::id('Completed'); }
    public static function voided(): int    { return self::id('Voided'); }
    public static function cancelled(): int { return self::id('Cancelled'); }

    /** Tests that create statuses inside a transaction need this between cases. */
    public static function flush(): void { self::$cache = []; }
}
