<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Services\TagPrintPolicy;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The tag population a clerk prints from.
 *
 * Reading only: this endpoint mints nothing and stamps nothing. It exists
 * because the per-product tag sheet is scoped to one product at one location,
 * while someone printing labels is working through a delivery — several
 * products, one receipt, one afternoon.
 *
 * Retired tags (serial_type 'issued') are excluded at the source. No print
 * mode will accept one, so listing them would offer a button that cannot
 * work.
 */
class BarcodeQueueController extends Controller
{
    use ApiResponse;

    /** Above this a "select all" stops being a selection and becomes a spool flood. */
    private const MAX_SELECTABLE = 200;

    public function __construct(private TagPrintPolicy $policy)
    {
    }

    public function index(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 24)));

        $page = $this->filtered($request)
            ->orderByDesc('serial_id')
            ->paginate($perPage);

        $page->getCollection()->transform(fn (ItemSerial $serial) => $this->row($serial));

        /* Counted over the same filter set the page was built from, so the
           header cannot disagree with the grid under it. The unsent count is
           a second query rather than a derived number: the page holds 24 rows
           and the answer is about all of them. */
        $response = $this->paginated($page);

        return $response->setData(array_merge($response->getData(true), [
            'counts' => [
                'total'  => $page->total(),
                'unsent' => (clone $this->filtered($request))->whereNull('last_printed_at')->count(),
            ],
        ]));
    }

    /**
     * Every id matching the current filters, for "select all matching".
     *
     * Capped, and the cap is reported rather than hidden: a clerk who thinks
     * they selected 340 labels and got 200 finds out at the shelf.
     */
    public function ids(Request $request)
    {
        $total = $this->filtered($request)->count();

        $ids = $this->filtered($request)
            ->orderByDesc('serial_id')
            ->limit(self::MAX_SELECTABLE)
            ->pluck('serial_id');

        return $this->success([
            'serial_ids' => $ids,
            'total'      => $total,
            'truncated'  => $total > self::MAX_SELECTABLE,
            'max'        => self::MAX_SELECTABLE,
        ]);
    }

    private function row(ItemSerial $serial): array
    {
        return [
            'serial_id'        => $serial->serial_id,
            'serial_number'    => $serial->serial_number,
            'supplier_barcode' => $serial->supplier_barcode,
            'tier'             => $this->policy->tier($serial),
            'bulk_printable'   => $this->policy->printable($serial, 'bulk'),
            'product_id'       => $serial->product_id,
            'product_name'     => $serial->product?->product_name,
            'product_type'     => $serial->product?->product_type,
            'location_name'    => $serial->location?->location_name,
            'receiving_number' => $serial->receiving?->receiving_number,
            'receiving_date'   => $serial->receiving?->receiving_date?->toDateString(),
            'print_count'      => (int) $serial->print_count,
            'last_printed_at'  => $serial->last_printed_at?->toIso8601String(),
        ];
    }

    /**
     * The filter set behind both index() and ids(), so a "select all
     * matching" can never mean something different from what is on screen.
     *
     * The eager loads are not optional: TagPrintPolicy::tier() calls
     * Product::isSerialized(), which lazy-loads applianceDetail /
     * consumableDetail per row when they are absent. Without them a page of
     * 24 rows fires a couple of hundred extra queries.
     *
     * Date and receipt filters reach through the receiving relation. A tag
     * with no receiving_id therefore drops out of those two — which is
     * correct: it has no answer to "what came in that week", and including it
     * would claim one.
     */
    private function filtered(Request $request): Builder
    {
        $query = ItemSerial::query()
            ->with([
                'product.applianceDetail', 'product.consumableDetail',
                'location', 'receiving',
            ])
            /* The same location scope Inventory and Receiving apply. A
               branch-scoped user must not be able to list — or select-all and
               print — another branch's labels. Applied before any request
               filter so no filter combination can widen it. */
            ->forUser($request->user())
            ->where('serial_type', '!=', 'issued');

        $query->when($request->filled('product_type'), fn (Builder $q) => $q
            ->whereHas('product', fn (Builder $p) => $p
                ->where('product_type', $request->input('product_type'))));

        $query->when($request->filled('location_id'), fn (Builder $q) => $q
            ->where('location_id', (int) $request->input('location_id')));

        $query->when($request->filled('date_from'), fn (Builder $q) => $q
            ->whereHas('receiving', fn (Builder $r) => $r
                ->whereDate('receiving_date', '>=', $request->input('date_from'))));

        $query->when($request->filled('date_to'), fn (Builder $q) => $q
            ->whereHas('receiving', fn (Builder $r) => $r
                ->whereDate('receiving_date', '<=', $request->input('date_to'))));

        /* One box for "which delivery": a GRN number or the PO it came from,
           because a clerk holding paperwork has whichever one is printed on
           it and should not have to know which field it is. */
        $query->when($request->filled('receipt'), function (Builder $q) use ($request) {
            $needle = trim((string) $request->input('receipt'));

            $q->whereHas('receiving', fn (Builder $r) => $r
                ->where('receiving_number', 'like', "%{$needle}%")
                ->orWhereHas('purchaseOrder', fn (Builder $po) => $po
                    ->where('po_number', 'like', "%{$needle}%")));
        });

        $query->when($request->filled('q'), function (Builder $q) use ($request) {
            $needle = trim((string) $request->input('q'));

            $q->whereHas('product', fn (Builder $p) => $p
                ->where('product_name', 'like', "%{$needle}%")
                ->orWhere('product_code', 'like', "%{$needle}%"));
        });

        /* `last_printed_at IS NULL` is THE definition of "unsent" — not
           `print_count = 0`. The two encode the same fact and nothing in the
           schema keeps them in step, so if stamping ever sets one without the
           other, a queue whose filter used one and whose badge used the other
           would disagree and nobody would notice. One authoritative column,
           used everywhere; it is also the indexed one. */
        $state = $request->input('print_state', 'all');

        if ($state === 'unsent') {
            $query->whereNull('last_printed_at');
        } elseif ($state === 'sent') {
            $query->whereNotNull('last_printed_at');
        }

        return $query;
    }
}
