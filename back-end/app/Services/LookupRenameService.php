<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Capacity and variant are stored on products as plain strings, not foreign
 * keys (see the design spec for why). That makes renaming a lookup value a
 * data-migration rather than a metadata change: without this cascade, a rename
 * silently orphans every product still holding the old string.
 */
class LookupRenameService
{
    /**
     * Repoint every row holding $old to $new.
     *
     * Contract: the caller owns the transaction; this method does not open one.
     * On a mid-loop failure the exception propagates, the return value is lost,
     * and tables already processed keep the new value while the rest keep the
     * old — so a standalone caller must wrap the call in its own transaction.
     *
     * @param  array<string,string>  $targets  [table => column]
     * @return int  Total rows updated across all targets.
     */
    public function cascade(array $targets, string $old, string $new): int
    {
        if ($old === $new) {
            return 0;
        }

        $total = 0;

        foreach ($targets as $table => $column) {
            // Deliberately a raw query builder update: it ignores soft-delete
            // scopes, so archived sets and products stay consistent too.
            //
            // BINARY forces a byte-exact comparison. The table's utf8mb4_unicode_ci
            // collation makes plain `where($column, $old)` case-insensitive, but the
            // lookup tables can legitimately hold values differing only in case
            // (e.g. "1.5HP" and "1.5hp" as two distinct rows, because products
            // reference the exact string). A ci match would conflate them: renaming
            // "1.5HP" would also silently rewrite every product holding "1.5hp",
            // orphaning that second lookup row.
            $total += DB::table($table)
                ->whereRaw("BINARY {$column} = ?", [$old])
                ->update([$column => $new]);
        }

        return $total;
    }
}
