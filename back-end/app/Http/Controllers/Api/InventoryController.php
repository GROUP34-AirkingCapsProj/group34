<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ReceivingDetail;
use App\Models\ItemSerial;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // Only rows whose product still exists in `products` (same catalog as Item Master).
        $query = Inventory::query()
            ->forUser($request->user())
            ->whereHas('product')
            ->with(['location'])
            ->with(['product' => function ($q) {
                $q->with([
                        'category',
                        'status',
                        'applianceDetail' => fn($aq) => $aq->with(['brand']),
                        'consumableDetail' => fn($cq) => $cq->with('unit'),
                    ])
                    ->withSum(['inventories as stock_on_hand_total' => function ($sub) {
                        // all locations — same basis as Item Master QTY
                    }], 'quantity_on_hand');
            }]);

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('category')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->whereHas('category', fn($c) => $c->where('category_name', $request->category));
            });
        }

        if ($request->filled('category_id')) {
            $query->whereHas('product', fn($q) => $q->where('category_id', $request->category_id));
        }

        if ($request->filled('stock_status')) {
            $recommended = '(SELECT recommended_stocks FROM products WHERE products.product_id = inventory.product_id)';
            $configuredMax = '(SELECT max_stock_level FROM products WHERE products.product_id = inventory.product_id)';
            $reorder = "COALESCE(NULLIF({$recommended}, 0), NULLIF(inventory.reorder_level, 0), 5)";
            $maximum = "COALESCE(NULLIF({$configuredMax}, 0), ({$reorder} * 4))";
            switch ($request->stock_status) {
                case 'out':
                    $query->where('quantity_on_hand', '<=', 0);
                    break;
                case 'critical':
                    $query->where('quantity_on_hand', '>', 0)
                        ->whereRaw("quantity_on_hand <= ({$reorder} * 0.5)");
                    break;
                case 'reorder':
                    $query->whereRaw("quantity_on_hand > ({$reorder} * 0.5)")
                        ->whereRaw("quantity_on_hand <= {$reorder}");
                    break;
                case 'overstock':
                    $query->whereRaw("quantity_on_hand >= {$maximum}");
                    break;
                case 'optimal':
                    $query->whereRaw("quantity_on_hand > {$reorder}")
                        ->whereRaw("quantity_on_hand < {$maximum}");
                    break;
            }
        }

        if ($request->has('low_stock') && $request->low_stock) {
            $query->whereRaw('quantity_on_hand <= (SELECT recommended_stocks FROM products WHERE products.product_id = inventory.product_id)');
        }

        $perPage = $request->input('per_page', 15);
        $page    = $query->paginate($perPage);

        return $this->paginated($this->withQuarantined($page), 'Inventory retrieved successfully');
    }

    /**
     * Say how much of each row is spoken for by an unsettled incident.
     *
     * Filing quarantines WITHOUT deducting: the goods are physically present
     * and still counted, they simply must not be promised or picked. The
     * picking engine already honours that — FifoPickingService, the issuance
     * pick plan and scan, and transfers all exclude quarantined batches and
     * subtract quarantined pieces — but this screen knew nothing about it. A
     * row with stock under investigation read exactly like one without, so the
     * person deciding whether they could promise it was reading the number the
     * picker would then be refused.
     *
     * DERIVED, never stored, and `quantity_on_hand` is left alone. That is the
     * same choice reservations make and it is what lets confirming or
     * dismissing a report return the stock with no cleanup step to forget —
     * which is exactly how batches end up excluded from picking forever by a
     * report nobody can see any more.
     *
     * One grouped query per distinct location on the page, not per row.
     */
    private function withQuarantined($page)
    {
        $byLocation = [];

        foreach ($page->getCollection()->pluck('location_id')->unique() as $locationId) {
            $byLocation[(int) $locationId] = \App\Models\IncidentItem::quarantinedQuantities((int) $locationId);
        }

        $page->getCollection()->transform(function ($row) use ($byLocation) {
            $held = (int) ($byLocation[(int) $row->location_id][(int) $row->product_id] ?? 0);

            $row->quarantined_quantity = $held;
            // What is actually left to promise. Without it every reader has to
            // do the subtraction themselves, and the one who forgets is the one
            // who promises stock the FIFO engine will refuse.
            $row->unquarantined_quantity = max(0, (int) $row->quantity_on_hand - $held);

            return $row;
        });

        return $page;
    }

    /**
     * GET /api/inventory/{inventory}/batches
     *
     * Returns the FIFO batch breakdown for one inventory row (one product at
     * one location), oldest batch first, legacy (untracked) batch last.
     * Used by the drill-down UI on the Inventory page.
     */
    public function batches(Inventory $inventory)
    {
        $batches = app(InventoryBatchService::class)
            ->batchesForDisplay($inventory->product_id, $inventory->location_id);

        $serialMap = app(\App\Services\BatchSerialResolver::class)->resolve($batches);

        $data = $batches->map(function ($batch) use ($serialMap) {
            return [
                'batch_id'           => $batch->batch_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
                'serials'            => $serialMap[$batch->batch_id] ?? [],
            ];
        });

        return $this->success($data, 'Inventory batches retrieved successfully');
    }

    public function show($id)
    {
        $inventory = Inventory::with([
            'location',
            'product.category',
            'product.status',
            'product.applianceDetail.brand',            'product.consumableDetail.unit',
        ])->find($id);

        if (!$inventory) {
            return $this->notFound('Inventory not found');
        }

        return $this->success($inventory, 'Inventory retrieved successfully');
    }

    public function byLocation($locationId)
    {
        $inventory = Inventory::where('location_id', $locationId)
            ->with([
                'product.category',
                'product.status',
                'product.applianceDetail.brand',                'product.consumableDetail.unit',
            ])
            ->get();

        return $this->success($inventory, 'Location inventory retrieved successfully');
    }

    public function byItem($product)
    {
        $productId = is_object($product) ? $product->product_id : $product;
        $inventory = Inventory::where('product_id', $productId)
            ->with(['location.branch'])
            ->get();

        return $this->success($inventory, 'Product inventory retrieved successfully');
    }

    /**
     * GET /api/inventory/{inventory}/history
     *
     * Returns the full stock-in history for one inventory row
     * (one product at one location). Used by the View detail panel.
     */
    public function stockHistory($id)
    {
        $inventory = Inventory::with([
            'location',
            'status',
            'product.category',
            'product.status',
            'product.applianceDetail.brand',            'product.consumableDetail.unit',
        ])->find($id);

        if (! $inventory) {
            return $this->notFound('Inventory record not found');
        }

        $product    = $inventory->product;
        $productId  = $inventory->product_id;
        $locationId = $inventory->location_id;

        if ($product && ! $product->relationLoaded('applianceDetail')) {
            $product->load('applianceDetail', 'consumableDetail');
        }

        $isSerialized     = $product?->isSerialized()        ?? false;
        $piecesPerPackage = $product?->getPiecesPerPackage() ?? 1;

        // ── Receiving history ─────────────────────────────────────────────
        $receivingDetails = ReceivingDetail::query()
            ->where('receiving_details.product_id', $productId)
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->where('receivings.location_id', $locationId)
            ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
            ->leftJoin('suppliers', 'suppliers.supplier_id', '=', 'purchase_orders.supplier_id')
            ->leftJoin('users', 'users.user_id', '=', 'receivings.received_by')
            ->select([
                'receiving_details.receiving_detail_id',
                'receiving_details.receiving_id',
                'receiving_details.scanned_barcode',
                'receiving_details.quantity_amount',
                'receiving_details.prod_price',
                'receiving_details.condition',
                'receiving_details.created_at as received_at',
                'receiving_details.updated_at',
                'receivings.receiving_number',
                'receivings.receiving_date',
                'receivings.po_id',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) AS received_by_name"),
                'purchase_orders.po_number',
                'purchase_orders.order_date as po_date',
                'suppliers.supplier_name',
                'suppliers.contact_person as supplier_contact',
                'suppliers.contact_number as supplier_phone',
            ])
            ->orderBy('receiving_details.created_at', 'asc')
            ->get();

        // ── Serial records ───────────────────────────────────────────────
        // Appliances (serialized): unit-level records from item_serial.
        // Installation materials (not serialized): receiving now mints a
        // digital tag (an item_serial row carrying a generated INT- serial)
        // per package / per counted lot / per scanned item, so those tags are
        // the unit-level records here too. Receipts booked before tagging
        // existed — and any other path that writes receiving_details without
        // minting — have no tags, so those receiving events still fall back to
        // one row per receiving_detail, keyed off the barcode scanned then.
        $serials = collect();
        if ($isSerialized) {
            $inStockSerials = ItemSerial::query()
                ->where('item_serial.product_id', $productId)
                ->where('item_serial.location_id', $locationId)
                ->leftJoin('users as scanner', 'scanner.user_id', '=', 'item_serial.scanned_by')
                ->leftJoin('receivings', 'receivings.receiving_id', '=', 'item_serial.receiving_id')
                ->leftJoin('transfers', 'transfers.transfer_id', '=', 'item_serial.transfer_id')
                ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
                ->leftJoin('suppliers', 'suppliers.supplier_id', '=', 'purchase_orders.supplier_id')
                ->leftJoin('status_lookup', 'status_lookup.status_id', '=', 'item_serial.status_id')
                ->select([
                    'item_serial.serial_id',
                    'item_serial.serial_number',
                    'item_serial.serial_type',
                    'item_serial.created_at as scanned_at',
                    'receivings.created_at as received_at_exact',
                    'transfers.updated_at as transferred_at',
                    DB::raw("CONCAT(scanner.first_name, ' ', scanner.last_name) AS scanned_by_name"),
                    'status_lookup.status_name as serial_status',
                    'receivings.receiving_number',
                    'receivings.receiving_date',
                    'purchase_orders.po_number',
                    'purchase_orders.order_date as po_date',
                    'suppliers.supplier_name',
                ])
                ->orderBy('item_serial.created_at', 'asc')
                ->get()
                ->map(function ($s) {
                    $s->row_quantity_received = 1;
                    $s->row_stock_in  = 1;
                    $s->row_stock_out = 0;
                    $s->row_transfer  = $s->serial_type === 'transferred' ? 1 : 0;
                    $s->received_at   = $s->received_at_exact ?? $s->scanned_at;
                    $s->stock_out_at  = null;
                    return $s;
                });

            $issuedSerials = DB::table('issuance_details as d')
                ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
                ->leftJoin('users as issuer', 'issuer.user_id', '=', 'i.issued_by')
                ->leftJoin('receivings as r', 'r.receiving_id', '=', 'd.receiving_id')
                ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
                ->leftJoin('suppliers as supplier', 'supplier.supplier_id', '=', 'po.supplier_id')
                ->where('d.product_id', $productId)
                ->where('i.location_id', $locationId)
                ->whereNotNull('d.serial_number')
                ->where('d.quantity_issued', '>', 0)
                ->select([
                    'd.issuance_detail_id as serial_id', 'd.serial_number',
                    DB::raw("'issued' as serial_type"), 'd.created_at as scanned_at',
                    'r.created_at as received_at_exact',
                    DB::raw("CONCAT(issuer.first_name, ' ', issuer.last_name) AS scanned_by_name"),
                    DB::raw("'Issued' as serial_status"),
                    'r.receiving_number', 'r.receiving_date', 'po.po_number',
                    'po.order_date as po_date', 'supplier.supplier_name',
                ])->get()->map(function ($s) {
                    $s->row_quantity_received = 1;
                    $s->row_stock_in = 0;
                    $s->row_stock_out = 1;
                    $s->row_transfer = 0;
                    $s->received_at = $s->received_at_exact;
                    $s->stock_out_at = $s->scanned_at;
                    $s->transferred_at = null;
                    return $s;
                });

            $serials = $inStockSerials->concat($issuedSerials)
                ->sortBy('scanned_at')->values();
        } else {
            // Digital tags minted for this material at this location. A tag is
            // an identifier for one physical thing (a package, a counted lot,
            // or a scanned item); MaterialTagService is explicit that it
            // carries no quantity of its own — quantity lives on the batch —
            // so the per-row quantity columns stay null rather than guessing.
            $materialTags = ItemSerial::query()
                ->where('item_serial.product_id', $productId)
                ->where('item_serial.location_id', $locationId)
                ->leftJoin('users as scanner', 'scanner.user_id', '=', 'item_serial.scanned_by')
                ->leftJoin('receivings', 'receivings.receiving_id', '=', 'item_serial.receiving_id')
                ->leftJoin('transfers', 'transfers.transfer_id', '=', 'item_serial.transfer_id')
                ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
                ->leftJoin('suppliers', 'suppliers.supplier_id', '=', 'purchase_orders.supplier_id')
                ->leftJoin('status_lookup', 'status_lookup.status_id', '=', 'item_serial.status_id')
                ->select([
                    'item_serial.serial_id',
                    'item_serial.serial_number',
                    'item_serial.supplier_barcode',
                    'item_serial.receiving_id',
                    'item_serial.serial_type',
                    'item_serial.created_at as scanned_at',
                    'receivings.created_at as received_at_exact',
                    'transfers.updated_at as transferred_at',
                    DB::raw("CONCAT(scanner.first_name, ' ', scanner.last_name) AS scanned_by_name"),
                    // A retired tag keeps its status_id (there is no "Issued"
                    // row in status_lookup's inventory category), so the label
                    // is fabricated from serial_type here - the same trick the
                    // appliance union above uses for its issued rows. Without
                    // it a spent tag would keep reading "In Stock".
                    DB::raw("CASE WHEN item_serial.serial_type = 'issued' THEN 'Issued' ELSE status_lookup.status_name END as serial_status"),
                    'receivings.receiving_number',
                    'receivings.receiving_date',
                    'purchase_orders.po_number',
                    'purchase_orders.order_date as po_date',
                    'suppliers.supplier_name',
                ])
                ->orderBy('item_serial.created_at', 'asc')
                ->orderBy('item_serial.serial_id', 'asc')
                ->get()
                ->map(function ($s) {
                    // A tag names a thing, it does not measure one. One tag can
                    // stand for a whole lot whose pieces drain over time, and
                    // several tags can share a batch, so neither 1 nor the
                    // batch quantity would be true here. Blank beats a number
                    // nobody can reconcile against.
                    $s->row_quantity_received = null;
                    $s->row_stock_in    = null;
                    $s->row_stock_out   = null;
                    $s->row_transfer    = null;
                    $s->received_at     = $s->received_at_exact ?? $s->scanned_at;
                    $s->stock_out_at    = null;
                    $s->is_material_tag = true;
                    return $s;
                });

            // Material issuance picks by batch, not by tag: FifoPickingService
            // writes serial_number => null on every consumable pick, so
            // issuance_details holds no per-tag serial to union in the way the
            // serialized branch does. Issued material tags are therefore not
            // listed; the Stock-Out history tab remains the record of what left.
            $taggedReceivingIds = $materialTags->pluck('receiving_id')
                ->filter()->unique()->all();

            // Pieces sold per receiving batch (issuance_details link a sale to its batch).
            $soldByReceiving = DB::table('issuance_details')
                ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
                ->leftJoin('inventory_batches', 'inventory_batches.batch_id', '=', 'issuance_details.batch_id')
                ->where('issuance_details.product_id', $productId)
                ->where('issuances.location_id', $locationId)
                ->whereRaw('COALESCE(issuance_details.receiving_id, inventory_batches.receiving_id) IS NOT NULL')
                ->groupByRaw('COALESCE(issuance_details.receiving_id, inventory_batches.receiving_id)')
                ->selectRaw('COALESCE(issuance_details.receiving_id, inventory_batches.receiving_id) AS receiving_id, '
                    . 'SUM(COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)) AS sold')
                ->pluck('sold', 'receiving_id');

            // Older/manual issue-out records may have deducted inventory without
            // storing a receiving/batch link. Allocate those pieces to receiving
            // rows oldest-first, which is the same FIFO rule used for deduction.
            $unlinkedSold = (int) DB::table('issuance_details')
                ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
                ->leftJoin('inventory_batches', 'inventory_batches.batch_id', '=', 'issuance_details.batch_id')
                ->where('issuance_details.product_id', $productId)
                ->where('issuances.location_id', $locationId)
                ->whereRaw('COALESCE(issuance_details.receiving_id, inventory_batches.receiving_id) IS NULL')
                ->sum(DB::raw('COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)'));

            if ($unlinkedSold > 0) {
                foreach ($receivingDetails->sortBy('received_at') as $rd) {
                    if ($unlinkedSold <= 0) break;
                    $alreadyAssigned = (int) ($soldByReceiving[$rd->receiving_id] ?? 0);
                    $batchCapacity = max(0, (int) $rd->quantity_amount - $alreadyAssigned);
                    $allocated = min($unlinkedSold, $batchCapacity);
                    if ($allocated > 0) {
                        $soldByReceiving[$rd->receiving_id] = $alreadyAssigned + $allocated;
                        $unlinkedSold -= $allocated;
                    }
                }
            }

            $stockOutAtByReceiving = DB::table('issuance_details as d')
                ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
                ->leftJoin('inventory_batches as b', 'b.batch_id', '=', 'd.batch_id')
                ->where('d.product_id', $productId)
                ->where('i.location_id', $locationId)
                ->where('d.quantity_issued', '>', 0)
                ->whereRaw('COALESCE(d.receiving_id, b.receiving_id) IS NOT NULL')
                ->groupByRaw('COALESCE(d.receiving_id, b.receiving_id)')
                ->selectRaw('COALESCE(d.receiving_id, b.receiving_id) AS receiving_id, MAX(d.created_at) AS stock_out_at')
                ->pluck('stock_out_at', 'receiving_id');
            $unlinkedStockOutAt = DB::table('issuance_details as d')
                ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
                ->leftJoin('inventory_batches as b', 'b.batch_id', '=', 'd.batch_id')
                ->where('d.product_id', $productId)
                ->where('i.location_id', $locationId)
                ->where('d.quantity_issued', '>', 0)
                ->whereRaw('COALESCE(d.receiving_id, b.receiving_id) IS NULL')
                ->max('d.created_at');

            $untaggedReceivingRows = $receivingDetails
                // received_at is receiving_details.created_at, a whole-second
                // `timestamp`, and the query underneath has no ORDER BY. Two
                // receivings booked in the same second therefore TIE, sortBy is
                // stable, and the rows come out in whatever order MariaDB felt
                // like returning them — so the same history screen could list
                // the batches in a different order on a refresh. The row id is
                // the insertion order, which is the order a reader expects.
                ->sortBy([['received_at', 'asc'], ['receiving_detail_id', 'asc']])
                // Receipts that minted tags are represented by those tags
                // above; listing the receiving row too would double them.
                ->reject(fn($rd) => in_array($rd->receiving_id, $taggedReceivingIds))
                ->map(function ($rd) use ($soldByReceiving, $stockOutAtByReceiving, $unlinkedStockOutAt) {
                    $received = (int) $rd->quantity_amount;
                    $stockOut = (int) ($soldByReceiving[$rd->receiving_id] ?? 0);
                    return (object) [
                    'serial_id'       => $rd->receiving_detail_id,
                    'serial_number'   => $rd->scanned_barcode ?: $rd->receiving_number,
                    'serial_type'     => 'received',
                    'scanned_at'      => $rd->received_at,
                    'received_at'     => $rd->received_at,
                    'stock_out_at'    => $stockOut > 0
                        ? ($stockOutAtByReceiving[$rd->receiving_id] ?? $unlinkedStockOutAt)
                        : null,
                    'transferred_at'  => null,
                    'scanned_by_name' => $rd->received_by_name,
                    'serial_status'   => 'Received',
                    'receiving_number'=> $rd->receiving_number,
                    'receiving_date'  => $rd->receiving_date,
                    'po_number'       => $rd->po_number,
                    'po_date'         => $rd->po_date,
                    'supplier_name'   => $rd->supplier_name,
                    'row_quantity_received' => $received,
                    'row_stock_in'    => max(0, $received - $stockOut),
                    'row_stock_out'   => $stockOut,
                    'row_transfer'    => null, // transfers are not batch-linked → not attributable per row
                    'supplier_barcode'=> $rd->scanned_barcode,
                    'is_material_tag' => false,
                    ];
                })
                ->values();

            $serials = $materialTags->concat($untaggedReceivingRows)
                ->sortBy('received_at')->values();
        }

        // ── Summary stats ─────────────────────────────────────────────────
        // Receiving/inventory quantities are pieces. For packaged materials,
        // prod_price and product cost are package prices and must be converted
        // before valuation.
        $totalCostValue = $receivingDetails->sum(
            fn($rd) => $product?->getPricePerPiece((float) $rd->prod_price)
                * (int) $rd->quantity_amount
        );
        $totalPieces  = $receivingDetails->sum('quantity_amount');
        $costPerPiece = $product?->getPricePerPiece((float) $product?->cost_price) ?? 0;
        $pricePerPiece = $product?->getPricePerPiece((float) $product?->unit_price) ?? 0;
        $avgCostPrice = $totalPieces > 0
            ? round($totalCostValue / $totalPieces, 2)
            : $costPerPiece;

        $latestReceiving = $receivingDetails->sortByDesc('received_at')->first();

        // ── Movement summary: distinguish customer sales from transfers ────
        // Sold and transferred pieces come from separate tables, so a transfer
        // can never be counted as a sale (and vice versa). Both work the same
        // for appliances and consumables — they are recorded as quantities.
        $soldPieces = (int) DB::table('issuance_details')
            ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
            ->where('issuance_details.product_id', $productId)
            ->where('issuances.location_id', $locationId)
            ->sum(DB::raw('COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)'));

        $transferredPieces = (int) DB::table('transfer_details')
            ->join('transfers', 'transfers.transfer_id', '=', 'transfer_details.transfer_id')
            ->where('transfer_details.product_id', $productId)
            ->where('transfers.from_location_id', $locationId)
            ->sum(DB::raw('COALESCE(transfer_details.quantity_transferred, 0)'));

        $movementHistory = collect();
        foreach ($receivingDetails as $rd) {
            $movementHistory->push([
                'type' => 'Stock In',
                'quantity' => (int) $rd->quantity_amount,
                'date_time' => $rd->received_at,
                'reference' => $rd->receiving_number,
                'serial_number' => $rd->scanned_barcode,
                'from_location' => $rd->supplier_name,
                'to_location' => $inventory->location?->location_name,
                'performed_by' => $rd->received_by_name,
            ]);
        }

        DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'i.issued_by')
            ->where('d.product_id', $productId)
            ->where('i.location_id', $locationId)
            ->where('d.quantity_issued', '>', 0)
            ->select([
                'd.serial_number', 'd.quantity_in_pieces', 'd.quantity_issued',
                'd.created_at', 'i.dr_number', 'i.issuance_id',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as performed_by"),
            ])->get()->each(function ($row) use ($movementHistory, $inventory) {
                $movementHistory->push([
                    'type' => 'Stock Out',
                    'quantity' => (int) ($row->quantity_in_pieces ?: $row->quantity_issued),
                    'date_time' => $row->created_at,
                    'reference' => $row->dr_number ?: ('Issuance #' . $row->issuance_id),
                    'serial_number' => $row->serial_number,
                    'from_location' => $inventory->location?->location_name,
                    'to_location' => 'Issued',
                    'performed_by' => $row->performed_by,
                ]);
            });

        $transferScans = DB::table('barcode_scans')
            ->where('product_id', $productId)
            ->where('scan_mode', 'transfer')
            ->whereNotNull('transfer_id')
            ->whereNotNull('barcode')
            ->orderBy('scanned_at')
            ->get(['transfer_id', 'barcode'])
            ->groupBy('transfer_id')
            ->map(fn($scans) => $scans
                ->pluck('barcode')
                ->filter()
                ->unique()
                ->values()
                ->all());

        DB::table('transfer_details as d')
            ->join('transfers as t', 't.transfer_id', '=', 'd.transfer_id')
            ->leftJoin('locations as src', 'src.location_id', '=', 't.from_location_id')
            ->leftJoin('locations as dst', 'dst.location_id', '=', 't.to_location_id')
            ->leftJoin('users as u', function ($join) {
                $join->on('u.user_id', '=', DB::raw('COALESCE(t.prepared_by, t.requested_by)'));
            })
            ->where('d.product_id', $productId)
            ->where('d.quantity_transferred', '>', 0)
            ->where(fn($q) => $q->where('t.from_location_id', $locationId)
                ->orWhere('t.to_location_id', $locationId))
            ->select([
                'd.quantity_transferred', 't.transfer_id', 't.transfer_number',
                't.transfer_date', 't.updated_at',
                'src.location_name as from_location',
                'dst.location_name as to_location',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as performed_by"),
            ])->get()->each(function ($row) use ($movementHistory, $transferScans) {
                $serialNumbers = $transferScans->get($row->transfer_id, []);

                $movementHistory->push([
                    'type' => 'Transfer',
                    'quantity' => (int) $row->quantity_transferred,
                    'date_time' => $row->updated_at ?: $row->transfer_date,
                    'reference' => $row->transfer_number,
                    'serial_number' => implode(', ', $serialNumbers),
                    'serial_numbers' => $serialNumbers,
                    'from_location' => $row->from_location,
                    'to_location' => $row->to_location,
                    'performed_by' => $row->performed_by,
                ]);
            });

        return $this->success([
            'inventory' => [
                'inventory_id'       => $inventory->inventory_id,
                'location_id'        => $inventory->location_id,
                'location_name'      => $inventory->location?->location_name,
                'location_type'      => $inventory->location?->location_type,
                'product_id'         => $inventory->product_id,
                'quantity_on_hand'   => $inventory->quantity_on_hand,
                'packages_on_hand'   => $inventory->packages_on_hand,
                'available_quantity' => $inventory->available_quantity,
                'reorder_level'      => $inventory->reorder_level,
                'last_updated'       => $inventory->last_updated ?? $inventory->updated_at,
                'status_name'        => $inventory->status?->status_name ?? 'Active',
                'created_at'         => $inventory->created_at,
                'updated_at'         => $inventory->updated_at,
            ],
            'product' => [
                'product_id'         => $product?->product_id,
                'product_code'       => $product?->product_code,
                'product_name'       => $product?->product_name,
                'product_type'       => $product?->product_type,
                'is_serialized'      => $isSerialized,
                'pieces_per_package' => $piecesPerPackage,
                'cost_price'         => $product?->cost_price,
                'unit_price'         => $product?->unit_price,
                'cost_per_piece'     => $costPerPiece,
                'price_per_piece'    => $pricePerPiece,
                'category_name'      => $product?->category?->category_name,
                'description'        => $product?->description,
                'brand_name'         => $product?->applianceDetail?->brand?->brand_name ?? null,
                'model_name'         => $product?->applianceDetail?->model_code ?? null,
                'capacity_rating'    => $product?->applianceDetail?->capacity_rating ?? null,
                'variant'            => $product?->applianceDetail?->variant ?? null,
                'warranty_months'    => $product?->applianceDetail?->warranty_period_months ?? null,
                'unit_name'          => $product?->consumableDetail?->unit?->unit_name ?? null,
                'unit_abbr'          => $product?->consumableDetail?->unit?->unit_abbreviation ?? null,
                'package_unit'       => $product?->consumableDetail?->package_unit ?? null,
            ],
            'receiving_history' => $receivingDetails,
            'serial_records'    => $serials,
            'movement_history'  => $movementHistory->sortBy('date_time')->values(),
            'summary' => [
                'total_receive_events'  => $receivingDetails->count(),
                'total_pieces_received' => $totalPieces,
                'total_cost_value'      => round($totalCostValue, 2),
                'avg_cost_price'        => $avgCostPrice,
                'last_stocked_at'       => $latestReceiving?->received_at ?? null,
                'latest_po_number'      => $latestReceiving?->po_number ?? null,
                'latest_supplier'       => $latestReceiving?->supplier_name ?? null,
                'serial_count'          => $serials->count(),
                'stock_in'              => (int) $totalPieces,
                'stock_out'             => $soldPieces,
                'transferred'           => $transferredPieces,
                'current_on_hand'       => (int) $inventory->quantity_on_hand,
                'current_available'     => (int) $inventory->available_quantity,
                'current_stock_value'   => round(
                    (int) $inventory->quantity_on_hand * $costPerPiece,
                    2
                ),
            ],
        ], 'Inventory history retrieved successfully');
    }

    public function scanBarcode(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'     => 'required|string',
                'location_id' => 'nullable|exists:locations,location_id',
            ]);

            $raw     = trim($validated['barcode']);
            $lower   = strtolower($raw);
            $upper   = strtoupper($raw);
            $digits  = preg_replace('/\D/', '', $raw) ?? '';
            $noSpace = preg_replace('/\s+/', '', $raw) ?? '';

            $candidates = array_values(array_unique(array_filter(
                [$raw, $lower, $upper, $noSpace, $digits],
                fn($v) => $v !== ''
            )));

            // Pass 1 — search products.barcode and products.product_code
            $product = null;
            foreach ($candidates as $c) {
                $norm = strtolower(trim($c));
                $product = Product::query()
                    ->where(function ($q) use ($norm) {
                        $q->whereRaw("LOWER(TRIM(COALESCE(barcode,''))) = ?",       [$norm])
                          ->orWhereRaw("LOWER(TRIM(COALESCE(product_code,''))) = ?", [$norm]);
                    })
                    ->with([
                        'category', 'status',
                        'applianceDetail' => fn($aq) => $aq->with(['brand']),
                        'consumableDetail' => fn($cq) => $cq->with('unit'),
                    ])
                    ->first();
                if ($product) {
                    break;
                }
            }

            // Pass 2 — search item_serials.serial_number (appliance units)
            if (! $product) {
                foreach ($candidates as $c) {
                    $serial = \App\Models\ItemSerial::query()
                        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($c))])
                        ->first();
                    if ($serial && $serial->product_id) {
                        $product = Product::query()
                            ->where('product_id', $serial->product_id)
                            ->with([
                                'category', 'status',
                                'applianceDetail' => fn($aq) => $aq->with(['brand']),
                                'consumableDetail' => fn($cq) => $cq->with('unit'),
                            ])
                            ->first();
                        if ($product) {
                            break;
                        }
                    }
                }
            }

            if (! $product) {
                return $this->notFound('Product not found with this barcode');
            }

            // Expose extension-table fields at the product level for backward-compat
            // (same shim pattern used by ProductController)
            if ($product->applianceDetail) {
                $ad = $product->applianceDetail;
                $product->setAttribute('brand_id',               $ad->brand_id);
                $product->setAttribute('capacity_rating',        $ad->capacity_rating);
                $product->setAttribute('variant',                $ad->variant);
                $product->setAttribute('warranty_period_months', $ad->warranty_period_months);
                $product->setAttribute('is_serialized',          $ad->is_serialized);
                $product->setAttribute('model_code',             $ad->model_code);
                $product->setAttribute('model_name',             $ad->model_code);
                $product->setRelation('brand', $ad->brand);
            }
            if ($product->consumableDetail) {
                $cd = $product->consumableDetail;
                $product->setAttribute('unit_id',            $cd->unit_id);
                $product->setAttribute('pieces_per_package', $cd->pieces_per_package);
                $product->setAttribute('package_unit',       $cd->package_unit);
                $product->setAttribute('is_serialized',      $cd->is_serialized);
                $product->setRelation('unit', $cd->unit);
            }

            $inventoryQuery = Inventory::where('product_id', $product->product_id)
                ->with(['location.branch']);

            if (! empty($validated['location_id'])) {
                $inventoryQuery->where('location_id', $validated['location_id']);
            }

            return $this->success([
                'product'            => $product,
                'inventory'          => $inventoryQuery->get(),
                // V5 helper fields for the frontend
                'product_type'       => $product->product_type,
                'is_serialized'      => $product->isSerialized(),
                'pieces_per_package' => $product->getPiecesPerPackage(),
                'brand_name'         => $product->applianceDetail?->brand?->brand_name   ?? null,
                'capacity'           => $product->applianceDetail?->capacity_rating       ?? null,
                'variant'            => $product->applianceDetail?->variant               ?? null,
                'unit_name'          => $product->consumableDetail?->unit?->unit_name     ?? null,
                'unit_abbr'          => $product->consumableDetail?->unit?->unit_abbreviation ?? null,
            ], 'Barcode scanned successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Scan failed: ' . $e->getMessage(), 500);
        }
    }
}
