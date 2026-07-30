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
            $total += DB::table($table)->where($column, $old)->update([$column => $new]);
        }

        return $total;
    }
}
