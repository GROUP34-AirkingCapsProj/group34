<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

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
     * The configured Unique rule for this lookup's name column: unique
     * among non-archived rows (deleted_at is null), ignoring this row's own
     * primary key on update. Table, primary key and column all come from
     * lookupModel()/lookupNameColumn(), so a controller never re-pastes
     * those three literals into its own lookupRules().
     *
     * Override this for a lookup whose uniqueness is additionally scoped by
     * another column.
     */
    protected function lookupUniqueRule(Request $request, $id = null): Unique
    {
        $modelClass = $this->lookupModel();
        $model = new $modelClass();

        $unique = Rule::unique($model->getTable(), $this->lookupNameColumn())
            ->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, $model->getKeyName());
        }

        return $unique;
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

    /**
     * Relations to eager-load on responses that return this lookup's rows
     * (index, archived and show). Empty by default. Override in a
     * controller whose model has a relation worth exposing (e.g. an
     * issuable-unit preset's unit) — one seam keeps that relation present in
     * every response shape instead of only the ones a subclass remembered to
     * touch.
     *
     * @return array<int,string>
     */
    protected function lookupEagerLoads(): array
    {
        return [];
    }

    /** Base listing query. Override lookupIndexFilters() to add filtering (e.g. by category). */
    protected function lookupIndexQuery(Request $request)
    {
        $model = $this->lookupModel();
        $query = $model::query()->with($this->lookupEagerLoads());

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
        $query = $model::onlyTrashed()->with($this->lookupEagerLoads())->orderBy('deleted_at', 'desc');

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
        $row = $model::with($this->lookupEagerLoads())->find($id);

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
            $this->transformRow($row->fresh($this->lookupEagerLoads()) ?? $row),
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
            $this->transformRow($row->fresh($this->lookupEagerLoads()) ?? $row),
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

    /**
     * lookupUniqueRule()'s ->whereNull('deleted_at') means create/update
     * correctly ignore archived rows when checking for a duplicate name —
     * but that same exemption means a name can be archived, reused by a
     * new active row, and then the archived one restored on top of it,
     * producing two active rows with the same name. Create is exempt from
     * this check on purpose (an archived value must never block a new
     * one); restore is not, because restoring is what turns an archived
     * name back into an active duplicate.
     *
     * Reuses lookupUniqueRule() (with an empty Request, since there is no
     * incoming payload to read here) rather than re-deriving the rule, so
     * this can never drift from whatever uniqueness create/update actually
     * enforce — including any extra scoping a controller overrides in.
     */
    public function restore($id)
    {
        $model = $this->lookupModel();
        $row = $model::onlyTrashed()->find($id);

        if (! $row) {
            return $this->notFound('Archived ' . strtolower($this->lookupLabel()) . ' not found');
        }

        $column = $this->lookupNameColumn();
        $value = (string) $row->{$column};

        $conflict = Validator::make(
            [$column => $value],
            [$column => [$this->lookupUniqueRule(new Request(), $id)]]
        );

        if ($conflict->fails()) {
            return $this->error(
                "Cannot restore: an active {$this->lookupLabel()} named '{$value}' already exists",
                409
            );
        }

        $row->restore();

        return $this->success($this->transformRow($row->fresh($this->lookupEagerLoads()) ?? $row), $this->lookupLabel() . ' restored');
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
