<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive uniqueness for a lookup's name column, among non-archived rows.
 *
 * Replaces Rule::unique() here because Rule::unique() compares with a plain `=`,
 * and what that means depends on the engine:
 *
 *   - MySQL: the lookup columns are utf8mb4_unicode_ci, so `=` is already
 *     case-insensitive. Creating "AIRCON" when "Aircon" exists is rejected.
 *   - PostgreSQL: `=` is case-sensitive, so the same request is ACCEPTED and two
 *     rows differing only in case end up in a table that is supposed to hold one
 *     name per concept.
 *
 * Comparing LOWER(column) explicitly makes both engines behave the way MySQL
 * always did, which is the behaviour the application and its tests expect.
 *
 * Note this is the opposite of App\Support\ExactStringMatch, deliberately.
 * That one keeps "1.5HP" and "1.5hp" apart because products reference the
 * capacity/variant string verbatim, so the two really are different values.
 * This one is about a human-facing NAME, where two spellings differing only in
 * case are the same lookup and must not both exist.
 *
 * Uses LOWER() rather than ILIKE or citext: both engines have it, and it does
 * not need an extension or a column-type change. It is not sargable without a
 * functional index — acceptable here because lookup tables hold tens to
 * hundreds of rows and this runs once per create/update.
 */
class UniqueLookupName implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column,
        private ?string $ignoreId = null,
        private ?string $keyName = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // 'required'/'string' rules own these cases.
        }

        $query = DB::table($this->table)
            // Archived rows are deliberately exempt: an archived name must not
            // block a new one. Matches the ->whereNull('deleted_at') this rule
            // replaced. Where the DB's own unique index disagrees (brand_lookup,
            // category_lookup), the controllers catch the violation and return
            // the same 422 this rule would have produced.
            ->whereNull('deleted_at')
            ->whereRaw(
                'LOWER('.DB::connection()->getQueryGrammar()->wrap($this->column).') = ?',
                [mb_strtolower($value, 'UTF-8')]
            );

        if ($this->ignoreId !== null && $this->keyName !== null) {
            $query->where($this->keyName, '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('The :attribute has already been taken.');
        }
    }
}
