<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared behaviour for lookups whose values are scoped to a category: a
 * category's own values plus the uncategorised ("global") ones apply, and a
 * value must be unique within that scope. Only capacity and variant use
 * this today — issuable-unit presets and the three retrofit targets are not
 * category-scoped.
 *
 * Consuming controllers must also use HandlesLookupCrud (for lookupModel()
 * and lookupNameColumn()).
 */
trait ScopesLookupsToCategory
{
    /**
     * A category's own values plus the uncategorised ones, which apply
     * everywhere. Without the category filter an aircon capacity shows up
     * when registering a refrigerator.
     */
    protected function lookupIndexFilters(Request $request, $query): void
    {
        if (! $request->filled('category_id')) {
            return;
        }

        $categoryId = $request->input('category_id');

        // Grouped in its own closure so this OR cannot later swallow any
        // further where() someone adds to this query (a `search` filter,
        // say — UnitLookupController already has one that this trait will
        // eventually need to accommodate). It is not currently load-bearing
        // against the soft-delete scope: Eloquent's own scope application
        // (Builder::applyScopes() -> addNewWheresWithinGroup()) already
        // wraps every pre-scope where in its own group before
        // SoftDeletingScope appends deleted_at is null, so a flat
        // where()->orWhereNull() here would not leak archived rows either.
        // The grouping is defensive against constraints this controller
        // might add next, not against the scope.
        $query->where(function ($q) use ($categoryId) {
            $q->where('category_id', $categoryId)->orWhereNull('category_id');
        });
    }

    /**
     * Overrides HandlesLookupCrud::lookupUniqueRule(): the configured Unique
     * rule for this lookup's name column, scoped to the category the row
     * belongs (or will belong) to.
     *
     * The category to validate against must come from the request only when
     * the request actually sent the key. On a PUT that renames the value
     * alone, category_id may be absent from the payload — the column isn't
     * being touched — and reading $request->input('category_id') would
     * silently return null — the global scope — even though the row itself
     * is scoped to a category. That checks uniqueness against a scope the
     * row was never in, so a rename can either collide with nothing
     * (letting a duplicate through) or collide with an unrelated global row
     * (rejecting a valid rename).
     */
    protected function lookupUniqueRule(Request $request, $id = null): Unique
    {
        $modelClass = $this->lookupModel();
        $model = new $modelClass();
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $column = $this->lookupNameColumn();

        $categoryId = $request->has('category_id')
            ? $request->input('category_id')
            : ($id !== null ? $modelClass::withTrashed()->find($id)?->category_id : null);

        $unique = Rule::unique($table, $column)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($categoryId) {
                // MySQL's unique index treats NULLs as distinct, so a DB-level
                // unique constraint alone would let two global (uncategorised)
                // rows share a value — that's why this is enforced here in
                // validation instead.
                //
                // The explicit whereNull/where split below is belt-and-braces,
                // not what makes that enforcement work: Query\Builder::where()
                // already converts a null value to whereNull() internally
                // (vendor/laravel/framework/.../Query/Builder.php ~line 936),
                // and Laravel's ConvertEmptyStringsToNull middleware normalises
                // an incoming '' to null before it ever reaches a controller.
                // So from an HTTP request $categoryId can only be null or a
                // real value — never '' — and $query->where('category_id', $categoryId)
                // alone would behave identically to this branch in both cases.
                // The branch exists only to protect a future non-HTTP caller
                // that passes '' directly (bypassing the middleware) and to
                // make the intent explicit for readers. Because no HTTP path
                // can reach the `where` arm with an empty string, this branch
                // cannot be killed by a feature/HTTP test — don't spend time
                // trying; if you need to prove it, test categoryScopedUniqueRule()
                // directly with $request->merge(['category_id' => '']).
                return ($categoryId === null || $categoryId === '')
                    ? $query->whereNull('category_id')
                    : $query->where('category_id', $categoryId);
            });

        if ($id !== null) {
            $unique->ignore($id, $primaryKey);
        }

        return $unique;
    }
}
