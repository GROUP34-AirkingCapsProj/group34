<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    /** Fully-qualified Eloquent model class, e.g. CapacityLookup::class. */
    abstract protected function lookupModel(): string;

    /** The user-visible name column, e.g. 'capacity_value'. */
    abstract protected function lookupNameColumn(): string;

    /** Singular display noun used in response messages, e.g. 'Capacity'. */
    abstract protected function lookupLabel(): string;

    /** Validation rules. $id is null on create, the primary key on update. */
    abstract protected function lookupRules(Request $request, $id = null): array;

    /** Plural display noun. Override when appending 's' is wrong. */
    protected function lookupLabelPlural(): string
    {
        return $this->lookupLabel() . 's';
    }

    /** How many product records reference this value. */
    protected function usageCountFor($row): int
    {
        return 0;
    }

    /**
     * Called inside the update transaction when the name column changed.
     * Return the number of product rows repointed.
     */
    protected function afterRename($row, string $oldName, string $newName): int
    {
        return 0;
    }

    /** Base listing query. Override to add filtering (e.g. by category). */
    protected function lookupIndexQuery(Request $request)
    {
        $model = $this->lookupModel();

        return $model::orderBy('sort_order')->orderBy($this->lookupNameColumn());
    }

    /** Shape of a single row in responses. */
    protected function transformRow($row): array
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
            $this->transformRow($row->fresh()),
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
                $cascaded = $this->afterRename($row, $oldName, $newName);
            }
        });

        return $this->success(
            $this->transformRow($row->fresh()),
            $cascaded > 0
                ? $this->lookupLabel() . " updated - {$cascaded} item(s) updated"
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
                ? $this->lookupLabel() . " archived - still used by {$usage} item(s)"
                : $this->lookupLabel() . ' archived'
        );
    }

    public function archived(Request $request)
    {
        $model = $this->lookupModel();

        $rows = $model::onlyTrashed()->orderBy('deleted_at', 'desc')->get();

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

        return $this->success($this->transformRow($row->fresh()), $this->lookupLabel() . ' restored');
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
            return $this->error("Cannot delete - used by {$usage} item(s)", 422);
        }

        try {
            $row->forceDelete();
        } catch (QueryException $e) {
            // A restrict-on-delete foreign key we did not count, e.g. a unit
            // referenced by an issuable unit preset.
            return $this->error('Cannot delete - this record is still referenced.', 422);
        }

        return $this->success(null, $this->lookupLabel() . ' permanently deleted');
    }

    /**
     * Fold one value into another: repoint the source's product rows onto the
     * target's name, then archive the source. Only meaningful for lookups that
     * implement afterRename(); routed for capacity and variant only.
     */
    public function merge(Request $request, $id)
    {
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
            $moved = $this->afterRename($source, (string) $source->{$column}, (string) $target->{$column});
            $source->delete();
        });

        return $this->success(
            [
                'moved'  => $moved,
                'target' => $this->transformRow($target->fresh()),
            ],
            "Merged - {$moved} item(s) moved"
        );
    }
}
