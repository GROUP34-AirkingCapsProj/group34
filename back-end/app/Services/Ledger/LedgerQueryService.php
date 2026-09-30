<?php

namespace App\Services\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every read the Audit & Evidence Explorer makes goes through here.
 *
 * Filtering, grouping and totalling all happen in the database. The page this
 * replaces pulled six sources into the browser in full and stitched them there,
 * which is how its numbers drifted from reality; keeping the arithmetic in one
 * server-side place is what stops that recurring.
 */
class LedgerQueryService
{
    /** Base query for a scope. Every read starts here so filters cannot drift apart. */
    public function scoped(LedgerFilter $f): Builder
    {
        $q = InventoryLedgerEntry::query()
            ->whereBetween('occurred_at', [$f->from, $f->to])
            ->where('lens', $f->lens);

        if ($f->productId) {
            $q->where('product_id', $f->productId);
        }
        if ($f->movementType) {
            $q->where('movement_type', $f->movementType);
        }
        if ($f->actorUserId) {
            $q->where('actor_user_id', $f->actorUserId);
        }
        if ($f->locationId) {
            // Either end of the route counts: a transfer out of a location and a
            // transfer into it are both that location's business.
            $q->where(function ($sub) use ($f) {
                $sub->where('from_location_id', $f->locationId)
                    ->orWhere('to_location_id', $f->locationId);
            });
        }
        if ($f->categoryId) {
            $q->whereIn('product_id', function ($sub) use ($f) {
                $sub->select('product_id')->from('products')->where('category_id', $f->categoryId);
            });
        }

        return $q;
    }

    /** @return array<string,mixed> */
    public function summary(LedgerFilter $f): array
    {
        $rows = $this->scoped($f)->get([
            'direction', 'quantity', 'extended_value', 'cost_basis', 'reverses_ledger_id', 'correlation_id',
        ]);

        $qtyIn  = (int) $rows->where('direction', 'in')->sum('quantity');
        $qtyOut = (int) $rows->where('direction', 'out')->sum('quantity');
        $valIn  = (float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value);
        $valOut = (float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value);

        $byBasis = [];
        foreach (InventoryLedgerEntry::COST_BASES as $basis) {
            $byBasis[$basis] = round(
                (float) $rows->where('cost_basis', $basis)->sum(fn ($r) => (float) $r->extended_value),
                2
            );
        }

        return [
            'quantity_in'    => $qtyIn,
            'quantity_out'   => $qtyOut,
            'net_quantity'   => $qtyIn - $qtyOut,
            'value_in'       => round($valIn, 2),
            'value_out'      => round($valOut, 2),
            'net_value'      => round($valIn - $valOut, 2),
            'event_count'    => $rows->pluck('correlation_id')->unique()->count(),
            'row_count'      => $rows->count(),
            'reversal_count' => $rows->whereNotNull('reverses_ledger_id')->count(),
            // Rows whose quantity we trust but whose value we could not establish.
            // Reported separately rather than folded in as zero, so a partial figure
            // can never read as a complete one.
            'unvalued_rows'  => $rows->whereNull('extended_value')->count(),
            'cost_basis'     => $byBasis,
        ];
    }

    public function movements(LedgerFilter $f, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 5), 200);

        $paginator = $this->scoped($f)
            ->with([
                'product:product_id,product_name,product_code',
                'actor:user_id,first_name,last_name,username',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('ledger_id')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (InventoryLedgerEntry $r) => $this->presentRow($r))
        );

        return $paginator;
    }

    /** @return array<string,mixed> */
    public function presentRow(InventoryLedgerEntry $r): array
    {
        $actor = $r->actor;
        $name  = $actor
            ? (trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: ($actor->username ?? 'System'))
            : 'System';

        return [
            'ledger_id'          => $r->ledger_id,
            'occurred_at'        => $r->occurred_at?->toIso8601String(),
            'posted_at'          => $r->posted_at?->toIso8601String(),
            'is_backdated'       => $r->isBackdated(),
            'direction'          => $r->direction,
            'movement_type'      => $r->movement_type,
            'product_id'         => $r->product_id,
            'product_name'       => $r->product?->product_name,
            'product_code'       => $r->product?->product_code,
            'batch_id'           => $r->batch_id,
            'serial_number'      => $r->serial_number,
            'quantity'           => (int) $r->quantity,
            'unit_cost'          => $r->unit_cost !== null ? (float) $r->unit_cost : null,
            'extended_value'     => $r->extended_value !== null ? (float) $r->extended_value : null,
            'cost_basis'         => $r->cost_basis,
            'from_location_id'   => $r->from_location_id,
            'to_location_id'     => $r->to_location_id,
            'source_type'        => $r->source_type,
            'source_id'          => $r->source_id,
            'source_line_id'     => $r->source_line_id,
            'correlation_id'     => $r->correlation_id,
            'actor_name'         => $name,
            'reverses_ledger_id' => $r->reverses_ledger_id,
            'origin'             => $r->origin,
            'notes'              => $r->notes,
        ];
    }

    /** Dimensions the Explorer may pivot by, mapped to the column they group on. */
    private const DIMENSIONS = [
        'movement_type' => 'movement_type',
        'product'       => 'product_id',
        'user'          => 'actor_user_id',
        'source'        => 'source_type',
        'cost_basis'    => 'cost_basis',
    ];

    /** @return array<int,array<string,mixed>> */
    public function breakdown(LedgerFilter $f, string $dimension): array
    {
        if (! isset(self::DIMENSIONS[$dimension])) {
            throw new \InvalidArgumentException(
                "'{$dimension}' is not a breakdown dimension. Available: "
                . implode(', ', array_keys(self::DIMENSIONS))
            );
        }

        $column = self::DIMENSIONS[$dimension];

        $rows = $this->scoped($f)->get(['direction', 'quantity', 'extended_value', $column, 'correlation_id']);

        $grouped = $rows->groupBy(fn ($r) => (string) ($r->{$column} ?? 'none'))
            ->map(function ($group, $key) {
                $in  = $group->where('direction', 'in');
                $out = $group->where('direction', 'out');

                return [
                    'key'          => $key,
                    'quantity_in'  => (int) $in->sum('quantity'),
                    'quantity_out' => (int) $out->sum('quantity'),
                    'value_in'     => round((float) $in->sum(fn ($r) => (float) $r->extended_value), 2),
                    'value_out'    => round((float) $out->sum(fn ($r) => (float) $r->extended_value), 2),
                    'row_count'    => $group->count(),
                    'event_count'  => $group->pluck('correlation_id')->unique()->count(),
                ];
            })
            ->values();

        // Resolve ids to names here so the table needs no second round trip.
        if ($column === 'product_id') {
            $names = \App\Models\Product::whereIn('product_id', $grouped->pluck('key'))
                ->pluck('product_name', 'product_id');
            $grouped = $grouped->map(fn ($r) => $r + ['label' => $names[$r['key']] ?? ('Product #' . $r['key'])]);
        } elseif ($column === 'actor_user_id') {
            $users = \App\Models\User::whereIn('user_id', $grouped->pluck('key'))->get();
            $grouped = $grouped->map(function ($r) use ($users) {
                $u = $users->firstWhere('user_id', (int) $r['key']);
                $label = $u
                    ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? 'Unknown'))
                    : 'System';

                return $r + ['label' => $label];
            });
        } else {
            $grouped = $grouped->map(fn ($r) => $r + ['label' => ucwords(str_replace('_', ' ', $r['key']))]);
        }

        return $grouped->sortByDesc(fn ($r) => $r['value_out'] + $r['value_in'])->values()->all();
    }

    /**
     * opening + in - out = closing, for quantity and for value.
     *
     * Opening is replayed from every movement before the window rather than read
     * from a stored snapshot: the ledger is append-only, so replaying it is the
     * authoritative answer and cannot drift from a cached figure that nobody
     * refreshed. Reversals need no special handling — a reversal is a real row in
     * the opposite direction, so it nets itself out.
     *
     * @return array<string,mixed>
     */
    public function reconciliation(LedgerFilter $f): array
    {
        $opening = $this->openingBalance($f);
        $window  = $this->summary($f);

        $closingQty   = $opening['quantity'] + $window['quantity_in'] - $window['quantity_out'];
        $closingValue = round($opening['value'] + $window['value_in'] - $window['value_out'], 2);

        // Checking closing against opening + in - out would compare the expression
        // to itself and always pass — a green light that verifies nothing. The only
        // check worth showing compares the ledger against something it did not
        // compute: the live stock figure. That needs a single product at a single
        // location to be meaningful, so it is reported as null for wider scopes
        // rather than faked.
        $onHand = $this->stockOnHand($f);

        return [
            'opening_quantity' => $opening['quantity'],
            'quantity_in'      => $window['quantity_in'],
            'quantity_out'     => $window['quantity_out'],
            'closing_quantity' => $closingQty,
            'opening_value'    => $opening['value'],
            'value_in'         => $window['value_in'],
            'value_out'        => $window['value_out'],
            'closing_value'    => $closingValue,
            // Independent verification, where the scope allows it.
            'stock_on_hand'    => $onHand,
            'matches_stock'    => $onHand === null ? null : ($onHand === $closingQty),
            'stock_variance'   => $onHand === null ? null : ($closingQty - $onHand),
            // Value can only be proven for rows that carried a cost. Surfaced, not hidden.
            'unvalued_rows'    => $window['unvalued_rows'],
            'value_provable'   => $window['unvalued_rows'] === 0,
        ];
    }

    /**
     * Live stock for the scope, or null when the scope covers more than one
     * product+location pair and a single number would be meaningless.
     *
     * Only meaningful once the ledger records every movement type. While phases 2-4
     * are unwired, a mismatch here is expected for products touched by incidents,
     * warranty or set picking — the drift check is where that is tracked.
     */
    private function stockOnHand(LedgerFilter $f): ?int
    {
        if (! $f->productId || ! $f->locationId) {
            return null;
        }

        $qty = \App\Models\Inventory::where('product_id', $f->productId)
            ->where('location_id', $f->locationId)
            ->value('quantity_on_hand');

        return $qty === null ? null : (int) $qty;
    }

    /** @return array{quantity:int, value:float} */
    private function openingBalance(LedgerFilter $f): array
    {
        // Built directly rather than through fromArray(), whose 366-day cap would
        // reject an unbounded opening window.
        $before = new LedgerFilter(
            from: \Illuminate\Support\Carbon::parse('1970-01-01')->startOfDay(),
            to: $f->from->copy()->subSecond(),
            locationId: $f->locationId,
            productId: $f->productId,
            categoryId: $f->categoryId,
            actorUserId: null,
            lens: $f->lens,
            movementType: null,
        );

        $rows = $this->scoped($before)->get(['direction', 'quantity', 'extended_value']);

        $qty = (int) $rows->where('direction', 'in')->sum('quantity')
             - (int) $rows->where('direction', 'out')->sum('quantity');
        $val = (float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value)
             - (float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value);

        return ['quantity' => $qty, 'value' => round($val, 2)];
    }
}
