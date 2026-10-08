<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\ReceivingDraft;
use App\Services\AirconLineGrouper;
use App\Services\BudgetService;
use App\Services\InventoryBatchService;
use App\Support\RealtimeBroadcaster;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ReceivingController extends Controller
{
    use ApiResponse, \App\Traits\NormalizesBarcode;

    public function __construct(private readonly AirconLineGrouper $airconLineGrouper)
    {
    }

    public function index(Request $request)
    {
        try {
            $query = Receiving::query()
                ->forUser($request->user())
                ->with(['purchaseOrder.details', 'purchaseOrder.supplier', 'location', 'receivedBy', 'details.product']);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('receiving_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Receivings retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve receivings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Persistent receiving log, grouped by purchase order (not one row per scan).
     * Every Receiving row is one scan; a busy PO can have dozens, but every row's
     * click-through leads to the same PO-level history timeline, so this groups
     * them into one summarized entry per PO.
     */
    public function poLog(Request $request)
    {
        try {
            $user = $request->user();

            $applyReceivingFilters = function ($q) use ($request, $user) {
                $q->forUser($user);
                if ($request->filled('location_id')) {
                    $q->where('location_id', $request->location_id);
                }
                if ($request->filled('from_date')) {
                    $q->whereDate('receiving_date', '>=', $request->from_date);
                }
                if ($request->filled('to_date')) {
                    $q->whereDate('receiving_date', '<=', $request->to_date);
                }
            };

            $poIdsQuery = Receiving::query();
            $applyReceivingFilters($poIdsQuery);
            $poIds = $poIdsQuery->distinct()->pluck('po_id');

            $query = PurchaseOrder::query()
                ->whereIn('po_id', $poIds)
                ->with('supplier')
                ->withCount(['receivings as events_count' => $applyReceivingFilters])
                ->withMax(['receivings as last_received_date' => $applyReceivingFilters], 'receiving_date')
                ->withSum(['receivings as total_items' => $applyReceivingFilters], 'total_quantity_received');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('po_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q2) use ($search) {
                            $q2->where('supplier_name', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $paginated = $query->orderByDesc('last_received_date')->paginate($perPage);

            $paginated->getCollection()->transform(function ($po) use ($applyReceivingFilters) {
                $whQuery = $po->receivings();
                $applyReceivingFilters($whQuery);
                $po->warehouses = $whQuery->with('location')->get()
                    ->pluck('location.location_name')
                    ->filter()
                    ->unique()
                    ->values();
                $po->total_items = (int) $po->total_items;
                return $po;
            });

            return $this->paginated($paginated, 'Receiving log retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve receiving log: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'pc_id'                          => 'required|exists:purchase_orders,po_id',
                'location_id'                    => 'required|exists:locations,location_id',
                'receiving_number'               => 'nullable|string|max:50',
                'receiving_date'                 => 'required|date',
                'received_by'                    => 'nullable|exists:users,user_id',
                'total_quantity_received'        => 'nullable|integer|min:0',
                'total_quantity_damaged'         => 'nullable|integer|min:0',
                'profit_loss_id'                 => 'nullable|exists:profit_loss,profit_loss_id',
                'status_id'                      => 'nullable|exists:status_lookup,status_id',
                'details'                        => 'required|array|min:1',
                'details.*.po_detail_id'         => 'nullable|exists:purchase_order_details,po_detail_id',
                'details.*.product_id'           => 'required|exists:products,product_id',
                'details.*.prod_price'           => 'nullable|numeric|min:0',
                'details.*.quantity_amount'      => 'required|integer|min:0',
                'details.*.condition'            => 'nullable|string|max:100',
                'details.*.scanned_barcode'      => 'nullable|string|max:191',
                'details.*.zone_label'           => 'nullable|string|max:100',
            ]);

            $poId       = (int) $validated['pc_id'];
            $locationId = (int) $validated['location_id'];
            $uid        = $request->user()?->user_id;

            $poForCheck = PurchaseOrder::find($poId);
            if ($poForCheck && $poForCheck->verification_status !== 'verified') {
                return $this->error('This purchase order must be verified by an auditor before receiving', 422);
            }

            DB::beginTransaction();

            $receivingData = Arr::except($validated, ['details']);
            $receivingData['po_id'] = $receivingData['pc_id'];
            unset($receivingData['pc_id']);
            // Insert with a short-lived unique value; the primary-key based GRN
            // is assigned immediately after creation.
            $receivingData['receiving_number'] = 'PENDING-' . str_replace('.', '', uniqid('', true));
            $receiving = Receiving::create($receivingData);
            $receiving->assignReadableNumber();

            $totalReceived = 0;
            foreach ($validated['details'] as $detail) {
                $productId = (int) $detail['product_id'];
                $qty       = (int) ($detail['quantity_amount'] ?? 0);
                $scannedBarcode = isset($detail['scanned_barcode'])
                    ? $this->canonicalSerial((string) $detail['scanned_barcode'])
                    : null;
                $scannedBarcode = $scannedBarcode !== '' ? $scannedBarcode : null;
                $zoneLabel = $detail['zone_label'] ?? null;

                $product = Product::with(['applianceDetail', 'installationMaterialDetail'])->find($productId);
                $isSerialized = $product ? $product->isSerialized() : false;

                ReceivingDetail::create([
                    'receiving_id'    => $receiving->receiving_id,
                    'po_detail_id'    => $detail['po_detail_id'] ?? null,
                    'product_id'      => $productId,
                    'quantity_amount' => $qty,
                    'prod_price'      => $detail['prod_price'] ?? 0,
                    'condition'       => $detail['condition'] ?? 'Good',
                    'scanned_barcode' => $scannedBarcode,
                ]);

                if ($isSerialized && $scannedBarcode) {
                    ItemSerial::create([
                        'product_id'    => $productId,
                        'location_id'   => $locationId,
                        'receiving_id'  => $receiving->receiving_id,
                        'scanned_by'    => $uid,
                        'serial_number' => $scannedBarcode,
                        'serial_type'   => 'received',
                        'status_id'     => 26,
                    ]);
                }

                // Update inventory at receiving location — lock the row before mutating it
                // and before calling InventoryBatchService, per its documented contract.
                $inventory = $this->lockInventory($locationId, $productId);
                $rcvPpp = ($product && ! $isSerialized) ? $product->getPiecesPerPackage() : 0;
                $inventory->setPieces((int) $inventory->quantity_on_hand + $qty, $rcvPpp);
                $inventory->save();

                app(InventoryBatchService::class)->createBatch(
                    $productId,
                    $locationId,
                    $receiving->receiving_id,
                    $qty,
                    $receiving->receiving_date ?? now(),
                    $zoneLabel
                );

                $totalReceived += $qty;
            }

            if (!isset($validated['total_quantity_received'])) {
                $receiving->update(['total_quantity_received' => $totalReceived]);
            }

            // Update PO status: Completed (11) or Partially Received (12)
            $totalOrdered  = PurchaseOrderDetail::where('po_id', $poId)->sum('quantity_ordered');
            $totalReceivedForPO = ReceivingDetail::whereIn(
                'receiving_id',
                Receiving::where('po_id', $poId)->pluck('receiving_id')
            )->sum('quantity_amount');

            $newPoStatus = ($totalReceivedForPO >= $totalOrdered) ? 11 : 12;
            PurchaseOrder::where('po_id', $poId)->update(['status_id' => $newPoStatus]);

            // Settle budget: single-warehouse POs settle at full completion; multi-
            // warehouse POs settle each warehouse group as its lines finish (spend()
            // is group-aware and idempotent per (po, budget)).
            $po = PurchaseOrder::with(['purchaseRequest', 'details'])->find($poId);
            if ($po) {
                $isMultiWarehouse = $po->details
                    ->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
                    ->count() > 1;
                if ($newPoStatus === 11 || $isMultiWarehouse) {
                    app(BudgetService::class)->spend($po, $request->user()?->user_id);
                }
            }

            DB::commit();

            RealtimeBroadcaster::inventoryChanged((int) $receiving->location_id, 'stock_received');
            if ($newPoStatus === 11) {
                $poLocationId = (int) (PurchaseOrder::where('po_id', $poId)->value('location_id') ?? $locationId);
                RealtimeBroadcaster::purchaseOrderChanged($poLocationId, 'po_received');
            }

            return $this->success(
                $receiving->load(['purchaseOrder', 'location', 'details.product']),
                'Receiving created successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create receiving: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Ensure an Inventory row exists for (location, product) and return it locked
     * (SELECT ... FOR UPDATE) within the caller's transaction. This satisfies
     * InventoryBatchService's contract that the relevant Inventory row must
     * already be locked before any of its methods are invoked.
     */
    private function lockInventory(int $locationId, int $productId): Inventory
    {
        Inventory::firstOrCreate(
            ['location_id' => $locationId, 'product_id' => $productId],
            ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
        );

        return Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    public function show($id)
    {
        try {
            $receiving = Receiving::with(['purchaseOrder.supplier', 'location', 'receivedBy', 'details.product'])->findOrFail($id);

            // array_merge over toArray() rather than setting an attribute on
            // the model: response()->json() on a model serialises through the
            // same toArray(), so every key the frontend already reads comes out
            // byte for byte, and aircon_groups is a pure sibling addition.
            return $this->success(
                array_merge($receiving->toArray(), [
                    'aircon_groups' => $this->airconGroups($receiving->details),
                ]),
                'Receiving retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Receiving not found', 404);
        }
    }

    /**
     * Aircon groups for this receipt's lines, or [] when no line is tagged.
     *
     * The early return is the common path, not an optimisation for a rare one:
     * every receipt written before aircon_model_id existed is untagged, and
     * those must not start paying for the grouper's three queries. The grouper
     * is called ONCE with every line — calling it per line would make a detail
     * view N+1 in the document's line count.
     *
     * Extra keys in a line array pass through the grouper untouched into the
     * group's `lines`, so receiving_detail_id rides along and the UI can match
     * a group back to the rows it already rendered.
     *
     * @param  \Illuminate\Support\Collection<int,ReceivingDetail>  $details
     * @return array<int,array<string,mixed>>
     */
    private function airconGroups($details): array
    {
        $lines  = [];
        $tagged = false;

        foreach ($details as $detail) {
            $modelId = $detail->aircon_model_id === null ? null : (int) $detail->aircon_model_id;
            $tagged  = $tagged || $modelId !== null;

            $lines[] = [
                'receiving_detail_id' => (int) $detail->receiving_detail_id,
                'product_id'          => (int) $detail->product_id,
                'quantity'            => (int) $detail->quantity_amount,
                'aircon_model_id'     => $modelId,
            ];
        }

        return $tagged ? $this->airconLineGrouper->group($lines) : [];
    }

    public function update(Request $request, $id)
    {
        try {
            $receiving = Receiving::findOrFail($id);

            $validated = $request->validate([
                'receiving_date'          => 'sometimes|date',
                'received_by'             => 'nullable|exists:users,user_id',
                'total_quantity_received' => 'nullable|integer|min:0',
                'total_quantity_damaged'  => 'nullable|integer|min:0',
                'profit_loss_id'          => 'nullable|exists:profit_loss,profit_loss_id',
                'status_id'               => 'nullable|exists:status_lookup,status_id',
            ]);

            $receiving->update($validated);

            return $this->success($receiving->load(['purchaseOrder', 'location', 'details.product']), 'Receiving updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update receiving: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $receiving = Receiving::findOrFail($id);
            $receiving->delete();
            return $this->success(null, 'Receiving deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete receiving: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return per-line receiving status for a PO: already-received piece counts
     * and all scanned barcodes stored against each line.
     */
    public function poScanStatus(int $poId)
    {
        try {
            $details = PurchaseOrderDetail::with('product')
                ->where('po_id', $poId)
                ->get(['po_detail_id', 'product_id', 'quantity_ordered']);

            if ($details->isEmpty()) {
                return $this->success(['lines' => [], 'scan_history' => []], 'No line items found for this PO');
            }

            $detailIds = $details->pluck('po_detail_id')->all();

            $receivedSums = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->groupBy('po_detail_id')
                ->selectRaw('po_detail_id, SUM(quantity_amount) as total_received')
                ->pluck('total_received', 'po_detail_id');

            // Packages are summed from what each row recorded, never derived from
            // pieces: piece verification lets a package post fewer pieces than its
            // nominal size, so dividing would floor a genuine 2 packs down to 1.
            // Rows predating the column contribute 0 (COALESCE), which is also the
            // right answer for appliances, where a package means nothing.
            $receivedPackageSums = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->groupBy('po_detail_id')
                ->selectRaw('po_detail_id, SUM(COALESCE(packages_received, 0)) as total_packages')
                ->pluck('total_packages', 'po_detail_id');

            // A barcode proves a carton arrived; it says nothing about what was
            // inside. A line counts as contents-verified only when NO row on it
            // is still waiting to be opened and checked.
            //
            // NULL here means exactly one thing — an unopened carton. Rows
            // predating the column were grandfathered by its migration rather
            // than left NULL, so no cutoff date is needed to keep historical
            // lines from nagging about receipts nobody can now check.
            $unverifiedCounts = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->whereNull('contents_verified_at')
                ->groupBy('po_detail_id')
                ->selectRaw('po_detail_id, COUNT(*) as unverified')
                ->pluck('unverified', 'po_detail_id');

            $scannedByLine = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->whereNotNull('scanned_barcode')
                ->where('scanned_barcode', '!=', '')
                ->select('po_detail_id', 'scanned_barcode')
                ->get()
                ->groupBy('po_detail_id')
                ->map(fn($rows) => $rows->pluck('scanned_barcode')->unique()->values()->all());

            $lines = $details->map(fn($d) => [
                'po_detail_id'     => $d->po_detail_id,
                'product_id'       => $d->product_id,
                'already_received'          => (int) ($receivedSums[$d->po_detail_id] ?? 0),
                'already_received_packages' => (int) ($receivedPackageSums[$d->po_detail_id] ?? 0),
                'scanned_barcodes'          => $scannedByLine[$d->po_detail_id] ?? [],
                // Serialized lines are exempt: an appliance is one unit carrying
                // one serial, with no package contents anyone could check, so
                // asking for verification would be a demand with no way to
                // satisfy it. A line with nothing received yet is vacuously
                // true — there is no unopened box on it.
                'contents_verified'         => $d->product?->isSerialized()
                    ? true
                    : (int) ($unverifiedCounts[$d->po_detail_id] ?? 0) === 0,
            ])->all();

            $productInfo = $details->keyBy('product_id')->map(fn($d) => [
                'name' => $d->product?->product_name ?? 'Unknown',
                'type' => $d->product?->product_type ?? 'installation_material',
                'pcs'  => (int) ($d->product?->pieces_per_package ?? 1),
            ]);

            $scanHistory = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->select(['receiving_detail_id', 'po_detail_id', 'product_id', 'quantity_amount', 'scanned_barcode', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($rd) use ($productInfo) {
                    $info = $productInfo[$rd->product_id] ?? ['name' => 'Unknown', 'type' => 'installation_material', 'pcs' => 1];
                    return [
                        'receiving_detail_id' => $rd->receiving_detail_id,
                        'po_detail_id'        => $rd->po_detail_id,
                        'product_id'          => $rd->product_id,
                        'product_name'        => $info['name'],
                        'is_serialized'       => $info['type'] === 'appliance',
                        'scanned_barcode'     => $rd->scanned_barcode,
                        'serial_number'       => $rd->scanned_barcode,
                        'quantity_amount'     => (int) $rd->quantity_amount,
                        'pieces_per_package'  => $info['pcs'],
                        'created_at'          => $rd->created_at?->toISOString(),
                    ];
                })
                ->all();

            // An open draft means goods are physically on the floor but not in
            // inventory. Surfaced here, not acted on: the receiving screen shows a
            // badge so the clerk resumes rather than starting over, and a human
            // always decides whether it posts or is discarded.
            $openDraft = ReceivingDraft::where('po_id', $poId)
                ->where('status', 'open')
                ->withCount('items')
                ->first();

            $openDraftPayload = $openDraft ? [
                'draft_id'      => (int) $openDraft->draft_id,
                'location_id'   => (int) $openDraft->location_id,
                'staged_count'  => (int) $openDraft->items_count,
                'staged_pieces' => $openDraft->stagedPieces(),
                'opened_at'     => $openDraft->created_at?->toIso8601String(),
            ] : null;

            return $this->success(
                ['lines' => $lines, 'scan_history' => $scanHistory, 'open_draft' => $openDraftPayload],
                'PO scan status retrieved'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve PO scan status: ' . $e->getMessage(), 500);
        }
    }

    /** Stream a PDF Goods Received Note for one receiving session. */
    public function receiptPdf(Request $request, $id)
    {
        $receiving = Receiving::with([
            'purchaseOrder.supplier', 'purchaseOrder.details',
            'supplier', 'location', 'receivedBy', 'details.product',
        ])->find($id);

        if (! $receiving) {
            return $this->error('Receiving not found', 404);
        }

        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }
        $paperWidths = ['a4' => 595.28, 'letter' => 612, 'legal' => 612, 'a5' => 419.53];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receiving.receipt', [
            'receiving' => $receiving,
            'printable_width_pt' => $paperWidths[$paperSize] - 72,
            'company' => [
                'name' => \App\Models\SystemSetting::companyName(),
                'address' => \App\Models\SystemSetting::companyAddress(),
                'email' => \App\Models\SystemSetting::companyEmail(),
                'phone' => \App\Models\SystemSetting::companyPhone(),
            ],
        ])
            ->setPaper($paperSize);

        $filename = $receiving->receiving_number . '.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** Stream a CSV Goods Received Note for one receiving session. */
    public function receiptCsv($id)
    {
        $receiving = Receiving::with([
            'purchaseOrder.details', 'supplier', 'location', 'receivedBy', 'details.product',
        ])->find($id);

        if (! $receiving) {
            return $this->error('Receiving not found', 404);
        }

        $orderedByProduct = collect($receiving->purchaseOrder?->details ?? [])
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('quantity_ordered'));

        $filename = $receiving->receiving_number . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($receiving, $orderedByProduct) {
            $handle = fopen('php://output', 'w');
            $poDetailsById = collect($receiving->purchaseOrder?->details ?? [])->keyBy('po_detail_id');
            $poDetailsByProduct = collect($receiving->purchaseOrder?->details ?? [])->groupBy('product_id');
            $receiptCostTotal = 0;
            fputcsv($handle, ['GRN Number', $receiving->receiving_number]);
            fputcsv($handle, ['PO Number', $receiving->purchaseOrder?->po_number ?? '']);
            fputcsv($handle, ['Supplier', $receiving->supplier?->supplier_name ?? $receiving->purchaseOrder?->supplier?->supplier_name ?? '']);
            fputcsv($handle, ['Warehouse', $receiving->location?->location_name ?? '']);
            fputcsv($handle, ['Received Date', (string) $receiving->receiving_date]);
            fputcsv($handle, []);
            fputcsv($handle, ['Product / Code', 'Ordered', 'Received', 'Unit Cost', 'Amount', 'Condition']);
            foreach ($receiving->details as $d) {
                $poLine = $poDetailsById[$d->po_detail_id] ?? ($poDetailsByProduct[$d->product_id] ?? collect())->first();
                $piecesPerOrderedUnit = $d->product?->product_type === 'appliance'
                    ? 1
                    : max(1, (int) ($d->product?->pieces_per_package ?? 1));
                $unitCost = $poLine
                    ? ((float) $poLine->unit_price / $piecesPerOrderedUnit)
                    : (float) ($d->product?->cost_price ?? 0);
                $lineCost = $unitCost * (int) $d->quantity_amount;
                $receiptCostTotal += $lineCost;
                fputcsv($handle, [
                    trim(($d->product?->product_name ?? ('Product #' . $d->product_id))
                        . (($d->product?->product_code ?? '') !== '' ? ' · ' . $d->product->product_code : '')),
                    $orderedByProduct[$d->product_id] ?? '',
                    (int) $d->quantity_amount,
                    number_format($unitCost, 2, '.', ''),
                    number_format($lineCost, 2, '.', ''),
                    $d->condition ?? 'Good',
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['Total Received Cost', '', '', '', number_format($receiptCostTotal, 2, '.', '')]);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // -----------------------------------------------------------------------
    // CONSOLIDATED PO RECEIPT — one Goods Received Note for everything received
    // against a PO, across all scan sessions, grouped by warehouse.
    // -----------------------------------------------------------------------

    /**
     * Aggregate every receiving recorded against a PO into a single receipt model:
     * warehouses → their received products (ordered vs received), plus the GRN
     * sessions and receivers that make up each warehouse's total.
     */
    private function buildPoReceiptModel(int $poId): ?array
    {
        $po = PurchaseOrder::with([
            'supplier',
            'location',
            'details.location',
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
        ])->find($poId);
        if (! $po) {
            return null;
        }

        $receivings = Receiving::with([
            'location',
            'receivedBy',
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
        ])
            ->where('po_id', $poId)
            ->orderBy('receiving_date')
            ->orderBy('receiving_id')
            ->get();

        // Ordered qty per (warehouse, product) from the PO lines.
        $orderedByWh = [];
        $costByWh = [];
        foreach ($po->details as $d) {
            $wh = (int) ($d->location_id ?? $po->location_id);
            $pid = (int) $d->product_id;
            $orderedByWh[$wh][$pid] =
                ($orderedByWh[$wh][$pid] ?? 0) + (int) $d->quantity_ordered;

            // PO prices are per ordered unit/package, while receiving quantities
            // are stored in base pieces. Convert to a base-unit cost for an
            // auditable received-value calculation.
            $piecesPerOrderedUnit = $d->product?->product_type === 'appliance'
                ? 1
                : max(1, (int) ($d->product?->installationMaterialDetail?->pieces_per_package ?? 1));
            $baseUnits = max(0, (int) $d->quantity_ordered) * $piecesPerOrderedUnit;
            $lineValue = (float) ($d->subtotal ?? ((float) $d->unit_price * (int) $d->quantity_ordered));
            $costByWh[$wh][$pid]['base_units'] = ($costByWh[$wh][$pid]['base_units'] ?? 0) + $baseUnits;
            $costByWh[$wh][$pid]['value'] = ($costByWh[$wh][$pid]['value'] ?? 0) + $lineValue;
        }

        $groups = [];
        foreach ($receivings as $r) {
            $whId = (int) $r->location_id;
            if (! isset($groups[$whId])) {
                $groups[$whId] = [
                    'name'      => $r->location?->location_name ?? ('Warehouse #' . $whId),
                    'received'  => [],
                    'products'  => [],
                    'serials'   => [],
                    'scans'     => [],
                    'conditions'=> [],
                    'sessions'  => [],
                    'receivers' => [],
                ];
            }
            $groups[$whId]['sessions'][] = [
                'number' => $r->receiving_number,
                'date'   => $r->receiving_date ? \Carbon\Carbon::parse($r->receiving_date)->format('m/d/Y') : '',
            ];
            $rn = $r->receivedBy
                ? trim(($r->receivedBy->first_name ?? '') . ' ' . ($r->receivedBy->last_name ?? ''))
                : '';
            if ($rn !== '') {
                $groups[$whId]['receivers'][$rn] = true;
            }
            foreach ($r->details as $d) {
                $pid = (int) $d->product_id;
                $groups[$whId]['received'][$pid] = ($groups[$whId]['received'][$pid] ?? 0) + (int) $d->quantity_amount;
                if (! isset($groups[$whId]['products'][$pid])) {
                    $product = $d->product;
                    $appliance = $product?->applianceDetail;
                    $material = $product?->installationMaterialDetail;
                    $groups[$whId]['products'][$pid] = [
                        'code'               => $appliance?->model_code ?? $product?->product_code ?? $product?->barcode ?? '',
                        'name'               => $product?->product_name ?? ('Product #' . $pid),
                        'type'               => $product?->product_type,
                        'brand'              => $appliance?->brand?->brand_name,
                        'capacity'           => $appliance?->capacity_rating,
                        'variant'            => $appliance?->variant,
                        'pieces_per_package' => max(1, (int) ($material?->pieces_per_package ?? 1)),
                        'package_unit'       => $material?->package_unit,
                        'unit'               => $material?->unit?->unit_abbreviation ?? $material?->unit?->unit_name,
                    ];
                }
                if ($d->scanned_barcode) {
                    $groups[$whId]['serials'][$pid][$d->scanned_barcode] = true;
                    if (! isset($groups[$whId]['scans'][$pid][$d->scanned_barcode])) {
                        $groups[$whId]['scans'][$pid][$d->scanned_barcode] = [
                            'serial'    => $d->scanned_barcode,
                            'received'  => 0,
                            'condition' => $d->condition ?: 'Good',
                        ];
                    }
                    $groups[$whId]['scans'][$pid][$d->scanned_barcode]['received'] += (int) $d->quantity_amount;
                }
                if ($d->condition) {
                    $groups[$whId]['conditions'][$pid][$d->condition] = true;
                }
            }
        }

        $warehouses    = [];
        $totalReceived = 0;
        foreach ($groups as $whId => $g) {
            $lines = [];
            foreach ($g['received'] as $pid => $qty) {
                $ordered = $orderedByWh[$whId][$pid] ?? ($orderedByWh[(int) $po->location_id][$pid] ?? null);
                $costBasis = $costByWh[$whId][$pid] ?? ($costByWh[(int) $po->location_id][$pid] ?? null);
                $unitCost = $costBasis && $costBasis['base_units'] > 0
                    ? $costBasis['value'] / $costBasis['base_units']
                    : 0;
                $lines[] = [
                    'code'     => $g['products'][$pid]['code'],
                    'name'     => $g['products'][$pid]['name'],
                    'type'     => $g['products'][$pid]['type'],
                    'brand'    => $g['products'][$pid]['brand'],
                    'capacity' => $g['products'][$pid]['capacity'],
                    'variant'  => $g['products'][$pid]['variant'],
                    'unit'     => $g['products'][$pid]['unit'],
                    'package_unit' => $g['products'][$pid]['package_unit'],
                    'pieces_per_package' => $g['products'][$pid]['pieces_per_package'],
                    'ordered'  => $ordered,
                    'received' => $qty,
                    'unit_cost' => $unitCost,
                    'line_cost' => $unitCost * $qty,
                    'serials'  => array_keys($g['serials'][$pid] ?? []),
                    'scans'    => array_values($g['scans'][$pid] ?? []),
                    'conditions' => array_keys($g['conditions'][$pid] ?? []),
                ];
                $totalReceived += $qty;
            }
            $warehouses[] = [
                'name'      => $g['name'],
                'sessions'  => $g['sessions'],
                'receivers' => array_keys($g['receivers']),
                'lines'     => $lines,
            ];
        }

        return [
            'po'             => $po,
            'warehouses'     => $warehouses,
            'total_received' => $totalReceived,
            'total_received_cost' => collect($warehouses)->sum(
                fn ($warehouse) => collect($warehouse['lines'])->sum('line_cost')
            ),
            'session_count'  => $receivings->count(),
            'receiving_numbers' => $receivings->pluck('receiving_number')->filter()->unique()->values()->all(),
            'received_date'  => (function () use ($receivings) {
                $dates = $receivings->pluck('receiving_date')->filter()
                    ->map(fn ($date) => \Carbon\Carbon::parse($date)->format('m/d/Y'))
                    ->unique()->values();
                if ($dates->isEmpty()) {
                    return '—';
                }
                return $dates->count() === 1
                    ? $dates->first()
                    : $dates->first() . ' – ' . $dates->last();
            })(),
        ];
    }

    /** Stream the consolidated PO Goods Received Note as a PDF. */
    public function poReceiptPdf(Request $request, $poId)
    {
        $model = $this->buildPoReceiptModel((int) $poId);
        if (! $model) {
            return $this->error('Purchase order not found', 404);
        }
        if ($model['session_count'] === 0) {
            return $this->error('No goods have been received against this purchase order yet', 404);
        }

        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }
        $paperWidths = ['a4' => 595.28, 'letter' => 612, 'legal' => 612, 'a5' => 419.53];
        $model['printable_width_pt'] = $paperWidths[$paperSize] - 72;
        $model['company'] = [
            'name' => \App\Models\SystemSetting::companyName(),
            'address' => \App\Models\SystemSetting::companyAddress(),
            'email' => \App\Models\SystemSetting::companyEmail(),
            'phone' => \App\Models\SystemSetting::companyPhone(),
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receiving.po_receipt', $model)->setPaper($paperSize);

        $filename = 'GRN-' . $model['po']->po_number . '.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** Stream the consolidated PO Goods Received Note as a CSV. */
    public function poReceiptCsv($poId)
    {
        $model = $this->buildPoReceiptModel((int) $poId);
        if (! $model) {
            return $this->error('Purchase order not found', 404);
        }
        if ($model['session_count'] === 0) {
            return $this->error('No goods have been received against this purchase order yet', 404);
        }

        $po       = $model['po'];
        $filename = 'GRN-' . $po->po_number . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($po, $model) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['PO Number', $po->po_number]);
            fputcsv($handle, ['Supplier', $po->supplier?->supplier_name ?? '']);
            fputcsv($handle, ['Receiving Sessions', $model['session_count']]);
            fputcsv($handle, ['Total Received', $model['total_received']]);
            fputcsv($handle, []);
            fputcsv($handle, ['Warehouse', 'Product / Code', 'Ordered', 'Received', 'Unit Cost', 'Amount']);
            foreach ($model['warehouses'] as $wh) {
                foreach ($wh['lines'] as $line) {
                    fputcsv($handle, [
                        $wh['name'],
                        trim($line['name'] . ($line['code'] ? ' · ' . $line['code'] : '')),
                        $line['ordered'] ?? '',
                        $line['received'],
                        number_format($line['unit_cost'], 2, '.', ''),
                        number_format($line['line_cost'], 2, '.', ''),
                    ]);
                }
            }
            fputcsv($handle, []);
            fputcsv($handle, ['Total Received Cost', '', '', '', '', number_format($model['total_received_cost'], 2, '.', '')]);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
