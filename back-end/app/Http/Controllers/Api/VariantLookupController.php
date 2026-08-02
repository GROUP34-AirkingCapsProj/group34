<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\VariantLookup;
use App\Services\LookupRenameService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VariantLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    public function __construct(private LookupRenameService $renamer)
    {
    }

    protected function lookupModel(): string
    {
        return VariantLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'variant_name';
    }

    protected function lookupLabel(): string
    {
        return 'Variant';
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
            'appliance_details' => 'variant',
            'product_sets'      => 'variant',
        ];
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = $this->lookupUniqueRule($request, $id);

        return [
            // max:100 is not cosmetic: variant_name must stay within what
            // appliance_details.variant (varchar(100)) can hold, or a cascade
            // would truncate under MySQL strict mode.
            'variant_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
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
            // (e.g. "Slim" vs "slim"), so an exact match is required — but
            // ->where($column, $value) first narrows through any ci index on
            // the column, then the BINARY comparison confirms the exact byte
            // match. A lone `BINARY col = ?` is non-sargable and would ignore
            // such an index outright.
            $count += DB::table($table)
                ->where($column, $row->variant_name)
                ->whereRaw("BINARY {$column} = ?", [$row->variant_name])
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
