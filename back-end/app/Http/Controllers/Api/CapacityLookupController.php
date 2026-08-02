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
        $unique = $this->lookupUniqueRule($request, $id);

        return [
            // max:20 is not cosmetic: capacity_value must stay within what
            // appliance_details.capacity_rating (varchar(20)) can hold, or a
            // cascade would truncate under MySQL strict mode.
            'capacity_value' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:20',
                $unique,
            ],
            'description' => 'nullable|string',
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
