<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\CapacityLookup;
use App\Services\LookupRenameService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CapacityLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    public function __construct(private LookupRenameService $renamer)
    {
    }

    protected function lookupModel(): string
    {
        return CapacityLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'capacity_value';
    }

    protected function lookupLabel(): string
    {
        return 'Capacity';
    }

    protected function lookupLabelPlural(): string
    {
        return 'Capacities';
    }

    /** capacity_lookup has a sort_order column, so order by it before the name. */
    protected function lookupOrderColumns(): array
    {
        return ['sort_order', 'capacity_value'];
    }

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

        // Nested closure is required: a flat where()->orWhereNull() would
        // escape the soft-delete scope and leak archived rows.
        $query->where(function ($q) use ($categoryId) {
            $q->where('category_id', $categoryId)->orWhereNull('category_id');
        });
    }

    /**
     * Every table/column pair holding this lookup's value as a plain string.
     * Both usageCountFor() and cascadeValueChange() read this, so the two can
     * never disagree about what counts as "in use".
     *
     * @return array<string,string>
     */
    protected function cascadeTargets(): array
    {
        return [
            'appliance_details' => 'capacity_rating',
            'product_sets'      => 'capacity_rating',
        ];
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        // The category to validate against must come from the request only
        // when the request actually sent the key. On a PUT that renames the
        // value alone, category_id is absent (it's "sometimes"), and reading
        // $request->input('category_id') would silently return null — the
        // global scope — even though the row itself is scoped to a category.
        // That checks uniqueness against a scope the row was never in, so a
        // rename can either collide with nothing (letting a duplicate through)
        // or collide with an unrelated global row (rejecting a valid rename).
        $categoryId = $request->has('category_id')
            ? $request->input('category_id')
            : ($id !== null ? CapacityLookup::withTrashed()->find($id)?->category_id : null);

        $unique = Rule::unique('capacity_lookup', 'capacity_value')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($categoryId) {
                // MySQL's unique index treats NULLs as distinct, so two global
                // rows could otherwise share a value. Branch explicitly.
                return ($categoryId === null || $categoryId === '')
                    ? $query->whereNull('category_id')
                    : $query->where('category_id', $categoryId);
            });

        if ($id !== null) {
            $unique->ignore($id, 'capacity_id');
        }

        return [
            'capacity_value' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:20',
                $unique,
            ],
            'category_id' => 'nullable|integer|exists:category_lookup,category_id',
            'description' => 'nullable|string',
            // 'sometimes', not 'nullable': the column is a NOT NULL
            // unsignedTinyInteger default 0, and with strict mode on, a JSON
            // {"sort_order": null} (an empty numeric form field, say) would
            // otherwise pass validation, reach the query builder as a literal
            // NULL, and raise SQLSTATE 1048 -> a 500. 'sometimes' means an
            // absent key is skipped (validated() omits it, the model falls
            // back to the column default), but a key that IS present is still
            // validated — so an explicit null fails 'integer' and returns a
            // clean 422 instead.
            'sort_order' => 'sometimes|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        $count = 0;

        foreach ($this->cascadeTargets() as $table => $column) {
            // Paired form, not a bare whereRaw: the collation is
            // case-insensitive and rows may legitimately differ only in case
            // (e.g. "1.5HP" vs "1.5hp"), so an exact match is required — but
            // ->where($column, $value) first narrows through any ci index on
            // the column, then the BINARY comparison confirms the exact byte
            // match. A lone `BINARY col = ?` is non-sargable and would ignore
            // such an index outright.
            $count += DB::table($table)
                ->where($column, $row->capacity_value)
                ->whereRaw("BINARY {$column} = ?", [$row->capacity_value])
                ->count();
        }

        return $count;
    }

    protected function supportsMerge(): bool
    {
        return true;
    }

    protected function cascadeValueChange(Model $row, string $from, string $to): int
    {
        return $this->renamer->cascade($this->cascadeTargets(), $from, $to);
    }
}
