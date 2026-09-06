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

        return $this->paginated($page);
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
     */
    private function filtered(Request $request): Builder
    {
        return ItemSerial::query()
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
    }
}
