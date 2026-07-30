<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The eight CRUD actions every lookup shares, plus merge.
 *
 * Before this existed, CategoryLookupController, BrandLookupController and
 * UnitLookupController were three near-identical copies of the same code. A
 * controller using this trait supplies configuration, not behaviour.
 *
 * Consuming controllers must also use App\Traits\ApiResponse.
 */
trait HandlesLookupCrud
{
    /**
     * Fully-qualified Eloquent model class, e.g. CapacityLookup::class.
     *
     * @return class-string<Model>
     */
    abstract protected function lookupModel(): string;

    /** The user-visible name column, e.g. 'capacity_value'. */
    abstract protected function lookupNameColumn(): string;

    /** Singular display noun used in response messages, e.g. 'Capacity'. */
    abstract protected function lookupLabel(): string;

    /** Validation rules. $id is null on create, the primary key on update. */
    abstract protected function lookupRules(Request $request, $id = null): array;

    /** How many product records reference this value. */
    abstract protected function usageCountFor(Model $row): int;

    /** Plural display noun. Override when appending 's' is wrong. */
    protected function lookupLabelPlural(): string
    {
        return $this->lookupLabel() . 's';
    }

    /**
     * Called inside the update transaction when the name column changed, and
     * inside the merge transaction to repoint the source's rows onto the
     * target's name. Return the number of product rows repointed.
     */
    protected function cascadeValueChange(Model $row, string $from, string $to): int
    {
        return 0;
    }

    /**
     * Whether this lookup supports merging one value into another. Only true
     * for lookups whose values live on products as strings and therefore
     * implement cascadeValueChange().
     */
    protected function supportsMerge(): bool
    {
        return false;
    }

    /**
     * Columns to order the list by. Defaults to the name column alone, because
     * only some lookup tables have sort_order — a default that referenced it
     * would fail at runtime for category_lookup, brand_lookup and unit_lookup.
     *
     * @return array<int,string>
     */
    protected function lookupOrderColumns(): array
    {
        return [$this->lookupNameColumn()];
    }

    /** Base listing query. Override lookupIndexFilters() to add filtering (e.g. by category). */
    protected function lookupIndexQuery(Request $request)
    {
        $model = $this->lookupModel();
        $query = $model::query();

        foreach ($this->lookupOrderColumns() as $column) {
            $query->orderBy($column);
        }

        $this->lookupIndexFilters($request, $query);

        return $query;
    }

    /**
     * Apply lookup-specific filtering to the active-list query. No-op by
     * default. Override in a controller to scope by category etc.; both
     * lookupIndexQuery() and (via lookupArchivedFilters()) the archived
     * query call this, so a subclass supplies filtering once.
     */
    protected function lookupIndexFilters(Request $request, $query): void
    {
    }

    /** Base archived-listing query. Override lookupArchivedFilters() to add filtering. */
    protected function lookupArchivedQuery(Request $request)
    {
        $model = $this->lookupModel();
        $query = $model::onlyTrashed()->orderBy('deleted_at', 'desc');

        $this->lookupArchivedFilters($request, $query);

        return $query;
    }

    /**
     * Apply lookup-specific filtering to the archived-list query. Defaults to
     * the same filtering as the active list, so a controller that scopes one
     * list scopes both unless it deliberately overrides this to differ.
     */
    protected function lookupArchivedFilters(Request $request, $query): void
    {
        $this->lookupIndexFilters($request, $query);
    }

    /** Shape of a single row in responses. */
    protected function transformRow(Model $row): array
    {
        return array_merge($row->toArray(), [
            'usage_count' => $this->usageCountFor($row),
        ]);
    }

    public function index(Request $request)
    {
        $rows = $this->lookupIndexQuery($request)->get();

        return $this->success(
            $rows->map(fn ($row) => $this->transformRow($row))->values()->all(),
            $this->lookupLabelPlural() . ' retrieved'
        );
    }

    public function show($id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        return $this->success($this->transformRow($row));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->lookupRules($request));
        $model = $this->lookupModel();
        $row = $model::create($validated);

        return $this->success(
            $this->transformRow($row->fresh() ?? $row),
            $this->lookupLabel() . ' created',
            201
        );
    }

    public function update(Request $request, $id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $validated = $request->validate($this->lookupRules($request, $id));

        $column = $this->lookupNameColumn();
        $oldName = (string) $row->{$column};
        $newName = array_key_exists($column, $validated)
            ? (string) $validated[$column]
            : $oldName;

        $cascaded = 0;

        // One transaction: a failed cascade must roll the rename back too,
        // otherwise the lookup and the products it names diverge permanently.
        DB::transaction(function () use ($row, $validated, $oldName, $newName, &$cascaded) {
            $row->update($validated);

            if ($newName !== $oldName) {
                $cascaded = $this->cascadeValueChange($row, $oldName, $newName);
            }
        });

        return $this->success(
            $this->transformRow($row->fresh() ?? $row),
            $cascaded > 0
                ? $this->lookupLabel() . " updated ({$cascaded} items updated)"
                : $this->lookupLabel() . ' updated'
        );
    }

    public function destroy($id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $usage = $this->usageCountFor($row);
        $row->delete();

        return $this->success(
            ['usage_count' => $usage],
            $usage > 0
                ? $this->lookupLabel() . " archived (still used by {$usage} items)"
                : $this->lookupLabel() . ' archived'
        );
    }

    public function archived(Request $request)
    {
        $rows = $this->lookupArchivedQuery($request)->get();

        return $this->success(
            $rows->map(fn ($row) => $this->transformRow($row))->values()->all(),
            'Archived ' . strtolower($this->lookupLabelPlural()) . ' retrieved'
        );
    }

    public function restore($id)
    {
        $model = $this->lookupModel();
        $row = $model::onlyTrashed()->find($id);

        if (! $row) {
            return $this->notFound('Archived ' . strtolower($this->lookupLabel()) . ' not found');
        }

        $row->restore();

        return $this->success($this->transformRow($row->fresh() ?? $row), $this->lookupLabel() . ' restored');
    }

    public function forceDestroy($id)
    {
        $model = $this->lookupModel();
        $row = $model::onlyTrashed()->find($id);

        if (! $row) {
            return $this->notFound('Archived ' . strtolower($this->lookupLabel()) . ' not found');
        }

        $usage = $this->usageCountFor($row);

        if ($usage > 0) {
            return $this->error("Cannot delete: used by {$usage} items", 422);
        }

        try {
            $row->forceDelete();
        } catch (QueryException $e) {
            // 23000 is the integrity-constraint SQLSTATE; anything else is a
            // genuine fault and must not be disguised as a usage conflict.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            Log::warning('Force-delete blocked by a foreign key', [
                'lookup' => $this->lookupLabel(),
                'id'     => $id,
            ]);

            return $this->error('Cannot delete: this record is still referenced', 422);
        }

        return $this->success(null, $this->lookupLabel() . ' permanently deleted');
    }

    /**
     * Fold one value into another: repoint the source's product rows onto the
     * target's name, then archive the source. Only supported by lookups that
     * opt in via supportsMerge() and implement cascadeValueChange().
     */
    public function merge(Request $request, $id)
    {
        if (! $this->supportsMerge()) {
            return $this->error('Merging is not supported for this lookup', 422);
        }

        $model = $this->lookupModel();
        $source = $model::find($id);

        if (! $source) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $validated = $request->validate(['target_id' => 'required|integer']);
        $target = $model::find($validated['target_id']);

        if (! $target) {
            return $this->notFound('Target ' . strtolower($this->lookupLabel()) . ' not found');
        }

        if ((int) $target->getKey() === (int) $source->getKey()) {
            return $this->error('Cannot merge a value into itself', 422);
        }

        $column = $this->lookupNameColumn();
        $moved = 0;

        DB::transaction(function () use ($source, $target, $column, &$moved) {
            $moved = $this->cascadeValueChange($source, (string) $source->{$column}, (string) $target->{$column});
            $source->delete();
        });

        return $this->success(
            [
                'moved'  => $moved,
                'target' => $this->transformRow($target->fresh() ?? $target),
            ],
            $this->lookupLabel() . " merged ({$moved} items moved)"
        );
    }
}
