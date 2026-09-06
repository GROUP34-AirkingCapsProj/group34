<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
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

    /** Above this a menu stops being a shortlist. Unlisted values stay
        reachable by narrowing another filter, so nothing is lost. */
    private const MAX_OPTIONS = 50;

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
            'options' => $this->options($request),
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
     * What the filter menus may offer.
     *
     * Built from filtered() - the same query the rows come from - so the
     * location scope is inherited rather than re-applied, and every value
     * offered is a value that returns rows. A menu assembled from the product
     * catalogue instead would list things nobody has received, and picking one
     * would land on an empty grid that reads exactly like a mistake.
     *
     * Ids are collected first and the display rows fetched separately: the
     * eager loads on filtered() are for rendering tags, and dragging them
     * through three DISTINCT queries would fire a page of joins to build a
     * list of names.
     */
    private function options(Request $request): array
    {
        $scoped = fn () => (clone $this->filtered($request))->setEagerLoads([]);

        $productIds = $scoped()->select('product_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('product_id');

        $locationIds = $scoped()->select('location_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('location_id');

        $receivingIds = $scoped()->whereNotNull('receiving_id')
            ->select('receiving_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('receiving_id');

        $receivings = Receiving::with('purchaseOrder')
            ->whereIn('receiving_id', $receivingIds)
            ->orderByDesc('receiving_date')
            ->get();

        /* One box searches GRN and PO numbers together, because a clerk holds
           whichever number the paperwork happened to print. `kind` is what
           lets the menu say which one a row is. */
        $receipts = $receivings
            ->flatMap(fn (Receiving $receiving) => array_values(array_filter([
                $receiving->receiving_number ? ['value' => $receiving->receiving_number, 'kind' => 'grn'] : null,
                $receiving->purchaseOrder?->po_number ? ['value' => $receiving->purchaseOrder->po_number, 'kind' => 'po'] : null,
            ])))
            ->unique('value')
            ->take(self::MAX_OPTIONS)
            ->values();

        return [
            'products' => Product::whereIn('product_id', $productIds)
                ->orderBy('product_name')
                ->get(['product_id', 'product_name', 'product_code']),
            'receipts' => $receipts,
            'locations' => Location::whereIn('location_id', $locationIds)
                ->orderBy('location_name')
                ->get(['location_id', 'location_name']),
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

        /* An exact product, from the picker. `q` below is still the substring
           search - it stays for URLs people have already shared. */
        $query->when($request->filled('product_id'), fn (Builder $q) => $q
            ->where('product_id', (int) $request->input('product_id')));

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
