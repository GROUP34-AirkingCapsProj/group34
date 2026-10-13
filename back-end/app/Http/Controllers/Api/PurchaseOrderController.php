<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RealtimeBroadcaster;
use App\Traits\ApiResponse;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Services\AirconLineGrouper;
use App\Services\History\RecordHistoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    private const WITH_RELATIONS = [
        'supplier',
        'location',
        'createdBy',
        'approvedBy',
        'verifiedBy',
        'receivedBy',
        'status',
        'purchaseRequest',
        'details.product.applianceDetail.brand',
        'details.product.installationMaterialDetail.unit',
        'details.approvedBy',
        'details.location',
    ];

    public function __construct(private readonly AirconLineGrouper $airconLineGrouper)
    {
    }

    public function index(Request $request)
    {
        try {
            $query = PurchaseOrder::with(self::WITH_RELATIONS);

            // Location visibility: all-location readers (admin, inventory_analyst,
            // branch_manager, auditor) see everything — the auditor must see every
            // PO to verify it, and the branch manager reads across branches. The one
            // read-scoped role, warehouse_personnel, sees POs for its assigned
            // location OR POs it personally created.
            $authUser = $request->user();
            if ($authUser && ! $authUser->readsAllLocations()) {
                $userId     = (int) $authUser->user_id;
                $locationId = $authUser->location_id ? (int) $authUser->location_id : null;
                $query->where(function ($q) use ($userId, $locationId) {
                    $q->where('created_by', $userId);
                    if ($locationId) {
                        // Header match (legacy single-warehouse POs) OR any line
                        // destined for this warehouse (per-line warehouse POs).
                        $q->orWhere('location_id', $locationId)
                          ->orWhereHas('details', fn ($d) => $d->where('location_id', $locationId));
                    }
                });
            }

            if ($request->has('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->filled('verification_status')) {
                $query->where('verification_status', $request->input('verification_status'));
            }
            if ($request->has('search')) {
                $query->where('po_number', 'like', '%' . $request->search . '%');
            }

            $perPage   = $request->get('per_page', 20);
            $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);
            $paginated->getCollection()->each(function ($po) {
                $balances = app(\App\Services\PoLineBalanceService::class)->forPo($po);
                $this->attachLifecycle($po, $balances);
                $po->details->transform(fn ($d) => $this->transformPoDetail($d, $balances[$d->po_detail_id] ?? null));
            });

            return $this->paginated($paginated, 'Purchase orders retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve purchase orders: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Normalize legacy field names from Draft PO Creator
            $payload = $request->all();
            if (empty($payload['po_number']) && !empty($payload['pc_number'])) {
                $payload['po_number'] = $payload['pc_number'];
            }
            if (empty($payload['po_number']) && !empty($payload['reference_no'])) {
                $payload['po_number'] = $payload['reference_no'];
            }
            if (empty($payload['order_date']) && !empty($payload['po_date'])) {
                $payload['order_date'] = $payload['po_date'];
            }
            if (empty($payload['location_id']) && !empty($payload['branch_id'])) {
                $payload['location_id'] = $payload['branch_id'];
            }
            if (empty($payload['details']) && !empty($payload['items']) && is_array($payload['items'])) {
                $payload['details'] = array_map(function ($item) {
                    return [
                        'product_id'      => $item['product_id'] ?? null,
                        'quantity_ordered' => $item['quantity'] ?? $item['qty'] ?? null,
                        'unit_price'       => $item['unit_price'] ?? $item['unit_cost'] ?? null,
                        'subtotal'         => $item['subtotal'] ?? $item['total'] ?? null,
                    ];
                }, $payload['items']);
            }

            $request->replace($payload);

            $validated = $request->validate([
                'supplier_id'                => 'required|exists:suppliers,supplier_id',
                'location_id'                => 'required|exists:locations,location_id',
                'po_number'                  => 'required|string|max:50|unique:purchase_orders,po_number',
                'order_date'                 => 'required|date',
                'expected_delivery_date'     => 'nullable|date',
                'total_amount'               => 'nullable|numeric|min:0',
                'grand_total'                => 'nullable|numeric|min:0',
                'status_id'                  => 'nullable|exists:status_lookup,status_id',
                'created_by'                 => 'nullable|exists:users,user_id',
                'approved_by'                => 'nullable|exists:users,user_id',
                'payment_terms'              => 'nullable|string|max:50',
                'notes'                      => 'nullable|string',
                'references_note'            => 'nullable|string',
                'ship_to_name'               => 'nullable|string|max:255',
                'ship_to_address'            => 'nullable|string',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            $loc = \App\Models\Location::find($validated['location_id']);
            if (!$loc || $loc->location_type !== 'warehouse') {
                return $this->error('Purchase orders are received at warehouses only — other locations are supplied by stock transfer.', 422);
            }

            $authUser = $request->user();
            if ($authUser) {
                $validated['created_by'] = $authUser->user_id;
            }

            DB::beginTransaction();

            $validated['status_id'] = 7;
            $validated['approved_by'] = null;
            $po = PurchaseOrder::create(Arr::except($validated, ['details']));

            $totalAmount = 0;
            foreach ($validated['details'] as $detail) {
                if (!isset($detail['subtotal'])) {
                    $detail['subtotal'] = $detail['quantity_ordered'] * $detail['unit_price'];
                }
                $po->details()->create($detail);
                $totalAmount += $detail['subtotal'];
            }

            if (!isset($validated['total_amount'])) {
                $po->update(['total_amount' => $totalAmount, 'grand_total' => $totalAmount]);
            }

            DB::commit();

            RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'po_created');
            RealtimeBroadcaster::approvalQueueChanged((int) $po->location_id, 'po_created');

            return $this->success(
                $po->load(self::WITH_RELATIONS),
                'Purchase order created successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create purchase order: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $po = PurchaseOrder::with(
                array_merge(self::WITH_RELATIONS, ['receivings', 'purchaseReturns'])
            )->findOrFail($id);

            // Built from the Eloquent lines, BEFORE transform() replaces them
            // with flat arrays.
            $airconGroups = $this->airconGroups($po->details);

            $balances = app(\App\Services\PoLineBalanceService::class)->forPo($po);
            $this->attachLifecycle($po, $balances);
            $po->details->transform(fn ($d) => $this->transformPoDetail($d, $balances[$d->po_detail_id] ?? null));

            // array_merge over toArray() rather than setting an attribute on
            // the model: the existing payload is reproduced byte for byte
            // (response()->json() on a model calls the same toArray()), and
            // nothing is bolted onto an instance that could later be saved.
            return $this->success(
                array_merge($po->toArray(), ['aircon_groups' => $airconGroups]),
                'Purchase order retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Purchase order not found', 404);
        }
    }

    /**
     * Aircon groups for this purchase order's lines, or [] when no line is
     * tagged.
     *
     * The early return is the common path, not an optimisation for a rare one:
     * every purchase order written before aircon_model_id existed is untagged,
     * and those must not start paying for the grouper's three queries. The
     * grouper is called ONCE with every line — calling it per line would make
     * a detail view N+1 in the document's line count.
     *
     * Extra keys in a line array pass through the grouper untouched into the
     * group's `lines`, so po_detail_id rides along and the UI can match a group
     * back to the rows it already rendered.
     *
     * @param  \Illuminate\Support\Collection<int,PurchaseOrderDetail>  $details
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
                'po_detail_id'    => (int) $detail->po_detail_id,
                'product_id'      => (int) $detail->product_id,
                'quantity'        => (int) $detail->quantity_ordered,
                'aircon_model_id' => $modelId,
            ];
        }

        return $tagged ? $this->airconLineGrouper->group($lines) : [];
    }

    /** Curated lifecycle timeline for one PO: audit milestones + receiving sessions. */
    public function history($id, RecordHistoryService $history)
    {
        $po = PurchaseOrder::with(['receivings.receivedBy'])->find($id);
        if (! $po) {
            return $this->error('Purchase order not found', 404);
        }

        $milestones = $history->forRecord('purchase_orders', (int) $po->po_id);

        foreach ($po->receivings as $r) {
            $receiver = $r->receivedBy
                ? trim(($r->receivedBy->first_name ?? '') . ' ' . ($r->receivedBy->last_name ?? ''))
                : 'System';
            $milestones[] = [
                'type'       => 'received',
                'label'      => 'Received (' . (int) $r->total_quantity_received . ' pcs) · ' . $r->receiving_number,
                'actor_name' => $receiver !== '' ? $receiver : 'System',
                'timestamp'  => ($r->receiving_date ?? $r->created_at)?->toIso8601String(),
                'changes'    => [],
            ];
        }

        usort($milestones, fn ($a, $b) => strcmp((string) $a['timestamp'], (string) $b['timestamp']));

        return $this->success(array_values($milestones), 'Purchase order history retrieved');
    }

    public function update(Request $request, $id)
    {
        try {
            $po = PurchaseOrder::findOrFail($id);

            $payload = $request->all();
            if (empty($payload['po_number']) && !empty($payload['pc_number'])) {
                $payload['po_number'] = $payload['pc_number'];
            }
            $request->replace($payload);

            $validated = $request->validate([
                'supplier_id'                => 'sometimes|exists:suppliers,supplier_id',
                'location_id'                => 'sometimes|exists:locations,location_id',
                'po_number'                  => ['sometimes', 'string', 'max:50', Rule::unique('purchase_orders', 'po_number')->ignore($po->po_id, 'po_id')],
                'order_date'                 => 'sometimes|date',
                'expected_delivery_date'     => 'nullable|date',
                'total_amount'               => 'nullable|numeric|min:0',
                'grand_total'                => 'nullable|numeric|min:0',
                'status_id'                  => 'nullable|exists:status_lookup,status_id',
                'approved_by'                => 'nullable|exists:users,user_id',
                'payment_terms'              => 'nullable|string|max:50',
                'notes'                      => 'nullable|string',
                'references_note'            => 'nullable|string',
                'ship_to_name'               => 'nullable|string|max:255',
                'ship_to_address'            => 'nullable|string',
                'details'                    => 'sometimes|array|min:1',
                'details.*.product_id'       => 'required_with:details|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required_with:details|integer|min:1',
                'details.*.unit_price'       => 'required_with:details|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            // Approval gate: transitioning to Approved (8), Authorized (10),
            // Completed (11), or Partially Received (12) requires an
            // auditor's verification first. This covers direct transitions
            // that would otherwise bypass verification (e.g. Pending straight
            // to Completed).
            if (isset($validated['status_id'])
                && in_array((int) $validated['status_id'], [8, 10, 11, 12], true)
                && $po->verification_status !== 'verified') {
                return $this->error('This purchase order must be verified by an auditor before it can be approved or received', 422);
            }

            if (array_key_exists('location_id', $validated)) {
                $loc = \App\Models\Location::find($validated['location_id']);
                if (!$loc || $loc->location_type !== 'warehouse') {
                    return $this->error('Purchase orders are received at warehouses only — other locations are supplied by stock transfer.', 422);
                }
            }

            DB::beginTransaction();

            $po = PurchaseOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($request->user()?->role?->role_name === 'branch_manager'
                && array_diff(array_keys($validated), ['status_id', 'approved_by', 'notes'])) {
                throw ValidationException::withMessages(['po' => 'Branch Manager can approve or reject POs; purchasing edits belong to Inventory Analyst or Admin.']);
            }
            if (array_key_exists('approved_by', $validated) && ! in_array((int) ($validated['status_id'] ?? 0), [8, 10], true)) {
                unset($validated['approved_by']);
            }
            $hasReceipts = $po->receivings()->exists() || $po->details()->whereHas('receivingDetails')->exists();
            $protected = ['details', 'supplier_id', 'location_id', 'total_amount', 'grand_total'];
            if (($hasReceipts || in_array((int) $po->status_id, [8, 10, 11, 12], true))
                && array_intersect(array_keys($validated), $protected)) {
                throw ValidationException::withMessages(['details' => 'Approved or received PO lines, prices, supplier and destination cannot be replaced.']);
            }
            if (isset($validated['status_id'])) {
                $target = (int) $validated['status_id'];
                if (! in_array($target, [7, 8, 9, 10], true)) {
                    throw ValidationException::withMessages(['status_id' => 'Receiving statuses are calculated from receipts; use dedicated lifecycle actions.']);
                }
                if (($hasReceipts || in_array((int) $po->status_id, [8, 10, 11, 12], true))
                    && $target !== (int) $po->status_id && ! ((int) $po->status_id === 8 && $target === 10 && ! $hasReceipts)) {
                    throw ValidationException::withMessages(['status_id' => 'An approved/received PO cannot be reset or rejected through general editing.']);
                }
                if (in_array($target, [8, 10], true)) {
                    if (! in_array($request->user()?->role?->role_name, ['admin', 'branch_manager'], true)) {
                        throw ValidationException::withMessages(['status_id' => 'Only Admin or Branch Manager can approve a PO.']);
                    }
                    if ($po->verification_status !== 'verified') {
                        throw ValidationException::withMessages(['status_id' => 'Auditor verification is required before approval.']);
                    }
                    $validated['approved_by'] = $request->user()->user_id;
                }
            }
            $header = Arr::except($validated, ['details']);
            if (!empty($header)) {
                $po->update($header);
            }

            if (isset($validated['details'])) {
                $po->details()->delete();
                $totalAmount = 0;
                foreach ($validated['details'] as $detail) {
                    if (!isset($detail['subtotal'])) {
                        $detail['subtotal'] = $detail['quantity_ordered'] * $detail['unit_price'];
                    }
                    $po->details()->create($detail);
                    $totalAmount += $detail['subtotal'];
                }
                $po->update([
                    'total_amount' => $totalAmount,
                    'grand_total'  => $validated['grand_total'] ?? $totalAmount,
                ]);
            }

            DB::commit();

            RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'po_updated');
            RealtimeBroadcaster::approvalQueueChanged((int) $po->location_id, 'po_updated');

            return $this->success(
                $po->fresh()->load(self::WITH_RELATIONS),
                'Purchase order updated successfully'
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update purchase order: ' . $e->getMessage(), 500);
        }
    }

    /** Auditor verifies a PO — required before admin approval and before receiving. */
    public function verify(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);
        if (!$po) {
            return $this->error('Purchase order not found', 404);
        }
        if ((int) $po->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot verify a purchase order you created', 422);
        }
        if ($po->verification_status === 'verified') {
            return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order already verified');
        }

        $po->update([
            'verification_status' => 'verified',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $request->input('note'),
        ]);

        RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'verification_changed');

        return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order verified');
    }

    /** Auditor rejects a PO (before approval) with a required reason; PO stays Pending. */
    public function reject(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);
        if (!$po) {
            return $this->error('Purchase order not found', 404);
        }
        if ((int) $po->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot reject a purchase order you created', 422);
        }
        if (in_array((int) $po->status_id, [8, 10, 11, 12], true)) {
            return $this->error('This purchase order has already been approved and can no longer be rejected in verification', 422);
        }

        try {
            $validated = $request->validate(['reason' => 'required|string|max:500']);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $po->update([
            'verification_status' => 'rejected',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $validated['reason'],
        ]);

        RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'verification_changed');

        return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order rejected');
    }

    public function destroy($id)
    {
        try {
            $po = PurchaseOrder::findOrFail($id);
            $po->delete();
            return $this->success(null, 'Purchase order archived successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to delete purchase order: ' . $e->getMessage(), 500);
        }
    }

    public function archived(Request $request)
    {
        $query = PurchaseOrder::onlyTrashed()->with(self::WITH_RELATIONS);

        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%' . $request->search . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived purchase orders retrieved'
        );
    }

    public function restore($id)
    {
        $po = PurchaseOrder::onlyTrashed()->find($id);
        if (! $po) {
            return $this->notFound('Archived purchase order not found');
        }
        $po->restore();
        return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order restored successfully');
    }

    public function forceDestroy($id)
    {
        $po = PurchaseOrder::onlyTrashed()->find($id);
        if (! $po) {
            return $this->notFound('Archived purchase order not found');
        }
        if ($po->receivings()->exists() || $po->purchaseReturns()->exists()
            || $po->details()->where('short_closed_quantity', '>', 0)->exists()) {
            return $this->error('This PO has permanent receiving or lifecycle evidence and must remain archived.', 409);
        }
        $po->forceDelete();
        return $this->success(null, 'Purchase order permanently deleted');
    }

    // -----------------------------------------------------------------------
    // PRODUCTS BY SUPPLIER (powers PO create form supplier → product dropdown)
    // -----------------------------------------------------------------------

    public function productsBySupplier(int $supplierId)
    {
        $products = \App\Models\Product::query()
            ->join('supplier_product', 'supplier_product.product_id', '=', 'products.product_id')
            ->where('supplier_product.supplier_id', $supplierId)
            ->with([
                'applianceDetail.brand',
                'installationMaterialDetail.unit',
            ])
            ->select('products.*')
            ->get()
            ->map(fn ($p) => [
                'product_id'         => $p->product_id,
                'product_code'       => $p->product_code,
                'product_name'       => $p->product_name,
                'product_type'       => $p->product_type,
                'is_serialized'      => $p->isSerialized(),
                'cost_price'         => $p->cost_price,
                'unit_price'         => $p->unit_price,
                'brand_name'         => $p->applianceDetail?->brand?->brand_name,
                'capacity_rating'    => $p->applianceDetail?->capacity_rating,
                'variant'            => $p->applianceDetail?->variant,
                'unit_name'          => $p->installationMaterialDetail?->unit?->unit_name,
                'unit_abbreviation'  => $p->installationMaterialDetail?->unit?->unit_abbreviation,
                'pieces_per_package' => $p->installationMaterialDetail?->pieces_per_package,
                'package_unit'       => $p->installationMaterialDetail?->package_unit,
            ]);

        return response()->json(['success' => true, 'data' => $products]);
    }

    // -----------------------------------------------------------------------
    // EXPORT
    // -----------------------------------------------------------------------

    public function export(Request $request)
    {
        $pos = PurchaseOrder::with([
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
            'details.location',
            'supplier', 'location', 'status',
            'purchaseRequest',
            'createdBy', 'approvedBy', 'verifiedBy', 'receivedBy',
        ])->orderBy('created_at', 'desc')->get();

        $filename = 'purchase-orders-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $csvColumns = [
            'PO Number', 'PR Number', 'Order Date', 'Expected Delivery',
            'Deliver To (Warehouse)', 'Supplier', 'Status',
            'Created By', 'Verified By', 'Verification Status', 'Verified At', 'Approved By', 'Received By',
            'Line #', 'Line Warehouse', 'Product Code', 'Product Name', 'Type',
            'Brand / Unit', 'Capacity / Pkg Unit', 'Pieces/Package',
            'Qty Ordered', 'Unit Price', 'Subtotal',
            'Total Amount', 'Grand Total',
        ];

        $callback = function () use ($pos, $csvColumns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $csvColumns);

            foreach ($pos as $po) {
                $deliverTo = $po->details
                    ->map(fn ($d) => $d->location?->location_name ?? $po->location?->location_name)
                    ->filter()->unique()->implode('; ');
                if ($deliverTo === '') {
                    $deliverTo = $po->location?->location_name ?? '';
                }

                $createdBy  = $po->createdBy
                    ? trim($po->createdBy->first_name  . ' ' . $po->createdBy->last_name)  : '';
                $approvedBy = $po->approvedBy
                    ? trim($po->approvedBy->first_name . ' ' . $po->approvedBy->last_name) : '';
                $receivedBy = $po->receivedBy
                    ? trim($po->receivedBy->first_name . ' ' . $po->receivedBy->last_name) : '';
                $verifiedBy = $po->verifiedBy
                    ? trim($po->verifiedBy->first_name . ' ' . $po->verifiedBy->last_name) : '';
                $verifiedAt = $po->verified_at ? $po->verified_at->format('Y-m-d') : '';

                if ($po->details->isEmpty()) {
                    fputcsv($handle, [
                        $po->po_number,
                        $po->purchaseRequest?->pr_number ?? '',
                        $po->order_date,
                        $po->expected_delivery_date ?? '',
                        $deliverTo,
                        $po->supplier?->supplier_name  ?? '',
                        $po->status?->status_name      ?? '',
                        $createdBy, $verifiedBy, $po->verification_status, $verifiedAt, $approvedBy, $receivedBy,
                        '', '', '', '', '', '', '', '', '', '', '',
                        number_format((float) $po->total_amount, 2),
                        number_format((float) $po->grand_total,  2),
                    ]);
                    continue;
                }

                foreach ($po->details as $i => $d) {
                    $p  = $d->product;
                    $ad = $p?->applianceDetail;
                    $cd = $p?->installationMaterialDetail;

                    fputcsv($handle, [
                        $po->po_number,
                        $po->purchaseRequest?->pr_number ?? '',
                        $po->order_date,
                        $po->expected_delivery_date ?? '',
                        $deliverTo,
                        $po->supplier?->supplier_name  ?? '',
                        $po->status?->status_name      ?? '',
                        $createdBy, $verifiedBy, $po->verification_status, $verifiedAt, $approvedBy, $receivedBy,
                        $i + 1,
                        $d->location?->location_name ?? $po->location?->location_name ?? '',
                        $ad?->model_code ?? $p?->product_code ?? $p?->barcode ?? '',
                        $p?->product_name ?? '',
                        $p ? ucfirst($p->product_type) : '',
                        $ad?->brand?->brand_name ?? $cd?->unit?->unit_name ?? '',
                        $ad?->capacity_rating    ?? $cd?->package_unit     ?? '',
                        $cd?->pieces_per_package ?? '',
                        $d->quantity_ordered,
                        number_format((float) $d->unit_price, 2),
                        number_format((float) $d->subtotal,   2),
                        number_format((float) $po->total_amount, 2),
                        number_format((float) $po->grand_total,  2),
                    ]);
                }
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /** Download the same formal document shown by the PO document preview. */
    public function pdf(Request $request, $id)
    {
        $po = PurchaseOrder::with(self::WITH_RELATIONS)->findOrFail($id);
        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('purchase_orders.document', [
            'po' => $po,
            'company' => [
                'name' => \App\Models\SystemSetting::companyName(),
                'address' => \App\Models\SystemSetting::companyAddress(),
                'email' => \App\Models\SystemSetting::companyEmail(),
            ],
            'paper_size' => $paperSize,
        ])->setPaper($paperSize);

        return $pdf->download($po->po_number . '.pdf');
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function attachLifecycle($po, array $balances): void
    {
        $state = \App\Support\PoLifecycle::compute(array_values($balances),
            in_array((int) $po->status_id, [8, 10, 11, 12], true),
            $po->lifecycle_status === \App\Support\PoLifecycle::CANCELLED);
        $po->setAttribute('lifecycle_status', $state);
        $po->setAttribute('lifecycle_label', \App\Support\PoLifecycle::LABELS[$state]);
        $po->setAttribute('lifecycle_review_issues', DB::table('po_lifecycle_exceptions')
            ->where('po_id', $po->po_id)->distinct()->pluck('code')->all());
    }

    private function transformPoDetail($detail, ?array $balance = null): array
    {
        $p  = $detail->product;
        $ad = $p?->applianceDetail;
        $cd = $p?->installationMaterialDetail;

        return [
            'po_detail_id'       => $detail->po_detail_id,
            'po_id'              => $detail->po_id,
            'product_id'         => $detail->product_id,
            'quantity_ordered'   => $detail->quantity_ordered,
            'unit_price'         => $detail->unit_price,
            'subtotal'           => $detail->subtotal,
            'quantity_balance'   => $balance,
            'order_unit'         => $detail->order_unit,
            'pieces_per_order_unit' => $detail->pieces_per_order_unit,
            'short_closed_quantity' => $detail->short_closed_quantity,
            // Destination warehouse for this line (per-line warehouse PO shape)
            'location_id'        => $detail->location_id,
            'location_name'      => $detail->location?->location_name,
            // Full destination-warehouse location so the UI can show each line's
            // real delivery address (multi-warehouse POs show one block/page each).
            'location'           => $detail->location ? [
                'location_id'    => $detail->location->location_id,
                'location_name'  => $detail->location->location_name,
                'address'        => $detail->location->address,
                'city'           => $detail->location->city,
                'province'       => $detail->location->province,
                'region'         => $detail->location->region,
                'contact_number' => $detail->location->contact_number,
                'email'          => $detail->location->email,
                'manager_name'   => $detail->location->manager_name,
            ] : null,
            // Flat product fields (legacy — kept for backward compat)
            'product_code'       => $ad?->model_code ?? $p?->product_code,
            'model_code'         => $ad?->model_code,
            'product_name'       => $p?->product_name,
            'product_type'       => $p?->product_type,
            'is_serialized'      => $p?->isSerialized() ?? false,
            'brand_name'         => $ad?->brand?->brand_name,
            'capacity_rating'    => $ad?->capacity_rating,
            'variant'            => $ad?->variant,
            'unit_name'          => $cd?->unit?->unit_name,
            'unit_abbreviation'  => $cd?->unit?->unit_abbreviation,
            'pieces_per_package' => $detail->pieces_per_order_unit ?? $cd?->pieces_per_package,
            'package_unit'       => $cd?->package_unit,
            // Whether this line is counted by the package or by the piece.
            // The receiving screen's count-entry panel prompts and converts on
            // this; defaulted here so the payload is self-describing rather
            // than relying on a client-side fallback.
            'count_unit'         => $cd?->count_unit ?? 'package',
            // Nested product object — required by BSR scan page (bsrActiveLine.product.X)
            'product'            => $p ? [
                'product_id'         => $p->product_id,
                'product_code'       => $ad?->model_code ?? $p->product_code,
                'model_code'         => $ad?->model_code,
                'product_name'       => $p->product_name,
                'product_type'       => $p->product_type,
                'is_serialized'      => $p->isSerialized(),
                'pieces_per_package' => $detail->pieces_per_order_unit ?? $cd?->pieces_per_package ?? 1,
                'package_unit'       => $cd?->package_unit,
                'count_unit'         => $cd?->count_unit ?? 'package',
                'brand_name'         => $ad?->brand?->brand_name,
                'capacity_rating'    => $ad?->capacity_rating,
                'variant'            => $ad?->variant,
                'unit_name'          => $cd?->unit?->unit_name,
                'unit_abbreviation'  => $cd?->unit?->unit_abbreviation,
            ] : null,
        ];
    }
}
