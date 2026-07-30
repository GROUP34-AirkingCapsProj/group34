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
    protected function lookupIndexQuery(Request $request)
    {
        $model = $this->lookupModel();
        $query = $model::query();

        foreach ($this->lookupOrderColumns() as $column) {
            $query->orderBy($column);
        }

        if ($request->filled('category_id')) {
            $categoryId = $request->input('category_id');

            $query->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)
                    ->orWhereNull('category_id');
            });
        }

        return $query;
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $categoryId = $request->input('category_id');

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
            'sort_order'  => 'nullable|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        // BINARY, not a plain where: the collation is case-insensitive, and a
        // later backfill can legitimately leave '1.5HP' and '1.5hp' as separate
        // rows. A ci match would report each one's products against both.
        return (int) DB::table('appliance_details')
            ->whereRaw('BINARY capacity_rating = ?', [$row->capacity_value])
            ->count();
    }

    protected function supportsMerge(): bool
    {
        return true;
    }

    protected function cascadeValueChange(Model $row, string $from, string $to): int
    {
        return $this->renamer->cascade(
            [
                'appliance_details' => 'capacity_rating',
                'product_sets'      => 'capacity_rating',
            ],
            $from,
            $to
        );
    }
}
