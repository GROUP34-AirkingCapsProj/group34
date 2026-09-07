<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
use App\Http\Controllers\Controller;
use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\Supplier;
use App\Models\Product;
use App\Services\BudgetService;
use App\Services\History\RecordHistoryService;
use App\Support\RealtimeBroadcaster;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private BudgetService $budgetService)
    {
    }

    private const WITH_RELATIONS = [
        'location',
        'supplier',
        'status',
        'requester',
        'approver',
        'budget',
        'convertedPO',
        'details.product.applianceDetail.brand',
        'details.product.installationMaterialDetail.unit',
        'details.location',
    ];

    /**
     * Live low-stock list for the PR create form. Unlike the analytics report,
     * this uses current inventory and does not require recommendation permissions.
     */
    public function lowStock(Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'required|integer|exists:locations,location_id',
        ]);

        $locationId = (int) $validated['location_id'];
        $user = $request->user();
        if (! $user->isCentralized() && (int) $user->scopedLocationId() !== $locationId) {
            return $this->forbidden('You do not have access to this warehouse.');
        }

        $stockAtLocation = DB::table('inventory')
            ->where('location_id', $locationId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) as current_stock');

        // Start from products, not inventory. A missing inventory row means the
        // warehouse has zero of that product and is therefore out of stock.
        $rows = Product::query()
            ->where('recommended_stocks', '>', 0)
            ->leftJoinSub($stockAtLocation, 'warehouse_stock', function ($join) {
                $join->on('warehouse_stock.product_id', '=', 'products.product_id');
            })
            ->with('installationMaterialDetail')
            ->select('products.*')
            ->selectRaw('COALESCE(warehouse_stock.current_stock, 0) as current_stock')
            ->get()
            ->filter(function ($product) {
                return (int) $product->current_stock < (int) $product->recommended_stocks;
            })
            ->map(function ($product) use ($locationId) {
                $current = max(0, (int) $product->current_stock);
                $target = max(1, (int) $product->recommended_stocks);
                $needed = max(1, $target - $current);
                $isMaterial = $product->product_type === 'installation_material';
                $piecesPerPackage = $isMaterial
                    ? max(1, (int) ($product->installationMaterialDetail?->pieces_per_package ?? 1))
                    : 1;

                return [
                    'recommendation_id' => 'live-' . $locationId . '-' . $product->product_id,
                    'product_id' => $product->product_id,
                    'location_id' => $locationId,
                    'current_stock' => $current,
                    'recommended_quantity' => $needed,
                    'priority' => $current === 0 ? 'CRITICAL' : 'HIGH',
                    'reason_codes' => [$current === 0 ? 'OUT_OF_STOCK' : 'LOW_STOCK'],
                    'pieces_per_package' => $isMaterial ? $piecesPerPackage : null,
                    'package_unit' => $isMaterial
                        ? ($product->installationMaterialDetail?->package_unit ?: null)
                        : null,
                    'order_packages' => $isMaterial
                        ? (int) ceil($needed / $piecesPerPackage)
                        : null,
                    'product' => [
                        'product_id' => $product->product_id,
                        'product_code' => $product->product_code,
                        'product_name' => $product->product_name,
                    ],
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($a['priority'] === 'CRITICAL' ? 0 : 1)
                    <=> ($b['priority'] === 'CRITICAL' ? 0 : 1),
                fn ($a, $b) => $b['recommended_quantity'] <=> $a['recommended_quantity'],
            ])
            ->values();

        return $this->success($rows, 'Live low-stock products retrieved');
    }

    /**
     * IA sees their own PRs.
     * When open_only=1 + product_id are both set, returns system-wide open
     * PR lines for that product (powers the smart reorder warning).
     */
    public function index(Request $request)
    {
        // Smart reorder check — system-wide open lines for a product
        if ($request->filled('product_id') && $request->input('open_only') == 1) {
            return $this->openLinesForProduct((int) $request->input('product_id'));
        }

        $perPage = $request->input('per_page', 20);

        $query = PurchaseRequest::with(self::WITH_RELATIONS)
            ->where('requested_by', $request->user()->user_id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->input('status_id'));
        }

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->each(function ($pr) {
            $pr->details->transform(fn ($d) => $this->transformPrDetail($d));
        });

        return $this->paginated($paginated, 'Purchase requests retrieved');
    }

    /**
     * Return all open PR lines (pending/approved/partial) for a product.
     * Includes remaining qty not yet covered by any PO.
     */
    private function openLinesForProduct(int $productId): \Illuminate\Http\JsonResponse
    {
        // Statuses: 13=Pending, 14=Approved, 16=Partially Converted
        $prs = PurchaseRequest::with([
                'details' => fn ($q) => $q->where('product_id', $productId)->with('product'),
                'status',
            ])
            ->whereIn('status_id', [13, 14, 16])
            ->whereHas('details', fn ($q) => $q->where('product_id', $productId))
            ->get();

        $openLines = [];
        foreach ($prs as $pr) {
            foreach ($pr->details as $detail) {
                $alreadyOrdered = (int) PurchaseOrderDetail::where('product_id', $productId)
                    ->whereHas('purchaseOrder', fn ($q) => $q->where('pr_id', $pr->pr_id))
                    ->sum('quantity_ordered');

                $remaining = $detail->quantity - $alreadyOrdered;
                if ($remaining > 0) {
                    $openLines[] = [
                        'pr_id'              => $pr->pr_id,
                        'pr_number'          => $pr->pr_number,
                        'status'             => $pr->status?->status_name,
                        'product_id'         => $productId,
                        'product_name'       => $detail->product?->product_name,
                        'quantity_requested' => $detail->quantity,
                        'quantity_ordered'   => $alreadyOrdered,
                        'quantity_remaining' => $remaining,
                        'needed_by_date'     => $pr->needed_by_date?->toDateString(),
                    ];
                }
            }
        }

        return $this->success($openLines, 'Open PR lines for product');
    }

    /**
     * Admin sees all pending PRs.
     */
    public function pending(Request $request)
    {
        $perPage = $request->get('per_page', 20);

        $query = PurchaseRequest::with(self::WITH_RELATIONS)
            ->where('status_id', 13)
            ->orderBy('created_at', 'desc');

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->each(function ($pr) {
            $pr->details->transform(fn ($d) => $this->transformPrDetail($d));
        });

        return $this->paginated($paginated, 'Pending purchase requests retrieved');
    }

    /**
     * Any authenticated user can view a PR detail.
     * Each detail line includes V5 product fields + quantity_ordered_in_po/remaining.
     */
    public function show($id)
    {
        $pr = PurchaseRequest::with(self::WITH_RELATIONS)->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }

        $prId = $pr->pr_id;
        $pr->details->transform(function ($detail) use ($prId) {
            $ordered = (int) PurchaseOrderDetail::where('product_id', $detail->product_id)
                ->whereHas('purchaseOrder', fn ($q) => $q->where('pr_id', $prId))
                ->sum('quantity_ordered');

            $row = $this->transformPrDetail($detail);
            $row['quantity_ordered_in_po'] = $ordered;
            $row['quantity_remaining']     = max(0, $detail->quantity - $ordered);
            return $row;
        });

        return $this->success($pr, 'Purchase request retrieved');
    }

    /** Curated lifecycle timeline for one PR: audit milestones + PO conversion. */
    public function history($id, RecordHistoryService $history)
    {
        $pr = PurchaseRequest::with('convertedPO')->find($id);

        $milestones = $history->forRecord('purchase_requests', (int) $id);

        if ($pr && $pr->convertedPO) {
            $milestones[] = [
                'type'       => 'converted',
                'label'      => 'Converted to PO ' . $pr->convertedPO->po_number,
                'actor_name' => 'System',
                'timestamp'  => $pr->convertedPO->created_at?->toIso8601String(),
                'changes'    => [],
            ];
        }

        usort($milestones, fn ($a, $b) => strcmp((string) $a['timestamp'], (string) $b['timestamp']));

        return $this->success(array_values($milestones), 'Purchase request history retrieved');
    }

    public function export(Request $request)
    {
        $prs = PurchaseRequest::with([
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
            'details.location',
            'location',
            'supplier',
            'status',
            'requester',
            'approver',
            'budget',
        ])->orderBy('created_at', 'desc')->get();

        $filename = 'purchase-requests-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $csvColumns = [
            'PR Number', 'Request Date', 'Needed By', 'Location',
            'Purpose', 'Status', 'Requested By', 'Approved By', 'Supplier',
            'Line #', 'Product Code', 'Product Name', 'Type',
            'Brand / Unit', 'Capacity / Pkg Unit', 'Pieces/Package',
            'Requested For (Warehouse)',
            'Quantity', 'Estimated Price', 'Subtotal', 'Notes',
            'Estimated Total',
        ];

        $callback = function () use ($prs, $csvColumns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $csvColumns);

            foreach ($prs as $pr) {
                $requestedBy = $pr->requester
                    ? trim($pr->requester->first_name . ' ' . $pr->requester->last_name) : '';
                $approvedBy  = $pr->approver
                    ? trim($pr->approver->first_name . ' ' . $pr->approver->last_name)  : '';
                $supplierName = $pr->supplier?->supplier_name ?? '';

                if ($pr->details->isEmpty()) {
                    fputcsv($handle, [
                        $pr->pr_number,
                        $pr->request_date,
                        $pr->needed_by_date ?? '',
                        $pr->location?->location_name ?? '',
                        $pr->purpose ?? '',
                        $pr->status?->status_name ?? '',
                        $requestedBy, $approvedBy,
                        $supplierName,
                        '', '', '', '', '', '', '', '', '', '', '', '',
                        number_format((float) $pr->estimated_total, 2),
                    ]);
                    continue;
                }

                foreach ($pr->details as $i => $d) {
                    $p  = $d->product;
                    $ad = $p?->applianceDetail;
                    $cd = $p?->installationMaterialDetail;

                    fputcsv($handle, [
                        $pr->pr_number,
                        $pr->request_date,
                        $pr->needed_by_date ?? '',
                        $pr->location?->location_name ?? '',
                        $pr->purpose ?? '',
                        $pr->status?->status_name ?? '',
                        $requestedBy, $approvedBy,
                        $supplierName,
                        $i + 1,
                        $p?->product_code ?? '',
                        $p?->product_name ?? '',
                        $p ? ucfirst($p->product_type) : '',
                        $ad?->brand?->brand_name ?? $cd?->unit?->unit_name   ?? '',
                        $ad?->capacity_rating    ?? $cd?->package_unit       ?? '',
                        $cd?->pieces_per_package ?? '',
                        $d->location?->location_name ?? '',
                        $d->quantity,
                        number_format((float) $d->estimated_price, 2),
                        number_format((float) $d->subtotal, 2),
                        $d->notes ?? '',
                        $i === 0 ? number_format((float) $pr->estimated_total, 2) : '',
                    ]);
                }
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function transformPrDetail($detail): array
    {
        $p  = $detail->product;
        $ad = $p?->applianceDetail;
        $cd = $p?->installationMaterialDetail;

        return [
            'pr_detail_id'       => $detail->pr_detail_id,
            'pr_id'              => $detail->pr_id,
            'product_id'         => $detail->product_id,
            'quantity'           => $detail->quantity,
            'estimated_price'    => $detail->estimated_price,
            'subtotal'           => $detail->subtotal,
            'notes'              => $detail->notes,
            // Destination warehouse (per-line, warehouse-only procurement)
            'location_id'        => $detail->location_id,
            'location_name'      => $detail->location?->location_name,
            // Product shared
            'product_code'       => $p?->product_code,
            'product_name'       => $p?->product_name,
            'product_type'       => $p?->product_type,
            'is_serialized'      => $p?->isSerialized() ?? false,
            // Appliance (null for installation materials)
            'brand_name'         => $ad?->brand?->brand_name,
            'capacity_rating'    => $ad?->capacity_rating,
            'variant'            => $ad?->variant,
            // Installation material (null for appliances)
            'unit_name'          => $cd?->unit?->unit_name,
            'unit_abbreviation'  => $cd?->unit?->unit_abbreviation,
            'pieces_per_package' => $cd?->pieces_per_package,
            'package_unit'       => $cd?->package_unit,
        ];
    }

    /**
     * IA submits a new PR.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'supplier_id'               => 'required|exists:suppliers,supplier_id',
                'needed_by_date'            => 'required|date|after:today',
                'purpose'                   => 'nullable|string',
                'details'                   => 'required|array|min:1',
                'details.*.product_id'      => 'required|exists:products,product_id',
                'details.*.location_id'     => 'required|exists:locations,location_id',
                'details.*.quantity'        => 'required|integer|min:1',
                'details.*.estimated_price' => 'required|numeric|min:0',
                'details.*.notes'           => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        // Every destination must be a warehouse.
        $locationTypes = \App\Models\Location::whereIn('location_id',
            collect($validated['details'])->pluck('location_id')->unique())
            ->pluck('location_type', 'location_id');
        foreach ($validated['details'] as $d) {
            if (($locationTypes[$d['location_id']] ?? null) !== 'warehouse') {
                return $this->error('Purchase orders are received at warehouses only — showrooms are supplied by stock transfer.', 422);
            }
        }

        // Product–supplier integrity: a product mapped to OTHER suppliers (and not
        // this one) is rejected; a product with NO mappings is allowed.
        $supplierId = (int) $validated['supplier_id'];
        foreach (collect($validated['details'])->pluck('product_id')->unique() as $pid) {
            $mappings = \App\Models\SupplierProduct::where('product_id', $pid)->pluck('supplier_id');
            if ($mappings->isNotEmpty() && !$mappings->contains($supplierId)) {
                $name = \App\Models\Product::where('product_id', $pid)->value('product_name');
                return $this->error("Product \"{$name}\" is not supplied by the selected supplier.", 422);
            }
        }

        // Merge target: the requester's own PENDING PR for the same supplier
        // (not soft-deleted, not yet converted). New lines fold into it instead
        // of minting a new PR.
        $mergeTarget = PurchaseRequest::where('status_id', 13)
            ->where('requested_by', $request->user()->user_id)
            ->where('supplier_id', $supplierId)
            ->whereNull('converted_to_po_id')
            ->with('details')
            ->first();

        // Per-warehouse advisory budget check (unlocked; approval re-checks under lock).
        // Computed on the COMBINED total (existing target lines + new lines) so a
        // merged PR cannot grow past what the warehouse budget can later approve.
        $groups = collect($validated['details'])->groupBy('location_id');
        foreach ($groups as $locId => $lines) {
            $newSubtotal = collect($lines)->sum(fn ($d) => $d['quantity'] * $d['estimated_price']);
            $existingSubtotal = $mergeTarget
                ? (float) $mergeTarget->details->where('location_id', (int) $locId)->sum('subtotal')
                : 0.0;
            $combined = (float) $newSubtotal + $existingSubtotal;

            $budget = $this->budgetService->resolveActiveBudget((int) $locId);
            $whName = \App\Models\Location::where('location_id', $locId)->value('location_name');
            if (!$budget) {
                return $this->error("{$whName} has no verified active budget for today.", 422);
            }
            if (!$this->budgetService->hasAvailable($budget, $combined)) {
                return $this->error(sprintf(
                    'Estimated total ₱%s for %s exceeds its available budget ₱%s (%s).',
                    number_format($combined, 2), $whName,
                    number_format(max((float) $budget->available, 0), 2), $budget->period_label
                ), 422);
            }
        }

        $estimatedTotal = collect($validated['details'])
            ->sum(fn ($d) => $d['quantity'] * $d['estimated_price']);

        try {
            $pr = DB::transaction(function () use ($validated, $request, $estimatedTotal, $supplierId, $mergeTarget) {
                if ($mergeTarget) {
                    foreach ($validated['details'] as $detail) {
                        $existing = $mergeTarget->details->first(fn ($d) =>
                            (int) $d->product_id === (int) $detail['product_id']
                            && (int) $d->location_id === (int) $detail['location_id']);

                        if ($existing) {
                            $existing->quantity = (int) $existing->quantity + (int) $detail['quantity'];
                            $existing->subtotal = round($existing->quantity * (float) $existing->estimated_price, 2);
                            $existing->save();
                        } else {
                            $mergeTarget->details->push(PurchaseRequestDetail::create([
                                'pr_id'           => $mergeTarget->pr_id,
                                'product_id'      => $detail['product_id'],
                                'location_id'     => $detail['location_id'],
                                'quantity'        => $detail['quantity'],
                                'estimated_price' => $detail['estimated_price'],
                                'subtotal'        => $detail['quantity'] * $detail['estimated_price'],
                                'notes'           => $detail['notes'] ?? null,
                            ]));
                        }
                    }
                    $mergeTarget->estimated_total = round($mergeTarget->details->sum('subtotal'), 2);
                    $mergeTarget->save();
                    return $mergeTarget;
                }

                $pr = PurchaseRequest::create([
                    'location_id'     => null,
                    'supplier_id'     => $supplierId,
                    'budget_id'       => null,
                    'pr_number'       => 'PR-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'request_date'    => today(),
                    'needed_by_date'  => $validated['needed_by_date'],
                    'purpose'         => $validated['purpose'] ?? null,
                    'estimated_total' => $estimatedTotal,
                    'status_id'       => 13,
                    'requested_by'    => $request->user()->user_id,
                ]);

                foreach ($validated['details'] as $detail) {
                    PurchaseRequestDetail::create([
                        'pr_id'           => $pr->pr_id,
                        'product_id'      => $detail['product_id'],
                        'location_id'     => $detail['location_id'],
                        'quantity'        => $detail['quantity'],
                        'estimated_price' => $detail['estimated_price'],
                        'subtotal'        => $detail['quantity'] * $detail['estimated_price'],
                        'notes'           => $detail['notes'] ?? null,
                    ]);
                }
                return $pr;
            });

            foreach (collect($validated['details'])->pluck('location_id')->unique() as $locId) {
                RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
            }

            $pr->load(self::WITH_RELATIONS);
            $merged = $mergeTarget !== null;
            return $this->success(
                $pr,
                $merged ? "Added to your pending request {$pr->pr_number}" : 'Purchase request submitted',
                $merged ? 200 : 201
            );
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin approves a PR and commits the budget.
     */
    public function approve(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details')->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isPending()) {
            return $this->error('Only pending purchase requests can be approved', 422);
        }

        $hasLineWarehouses = $pr->details->whereNotNull('location_id')->isNotEmpty();

        if (!$hasLineWarehouses && !$pr->budget_id) {
            return $this->error(
                'This purchase request has no budget attached (created before budget enforcement). Reject it and ask the requester to submit a new one.',
                422
            );
        }

        try {
            DB::transaction(function () use ($pr, $request, $hasLineWarehouses) {
                // Validate availability WITHOUT reserving — reservation now happens
                // at PO approval. A PR is only a request.
                if ($hasLineWarehouses) {
                    $groups = $pr->details->groupBy('location_id');
                    foreach ($groups as $locId => $lines) {
                        $subtotal = (float) $lines->sum('subtotal');
                        $budget = $this->budgetService->resolveActiveBudget((int) $locId);
                        if (!$budget) {
                            $whName = \App\Models\Location::where('location_id', $locId)->value('location_name');
                            throw new UnverifiedBudgetException($whName ?? "warehouse #{$locId}", 'pending');
                        }
                        if (!$this->budgetService->hasAvailable($budget, $subtotal)) {
                            $available = (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent;
                            throw new InsufficientBudgetException($budget->period_label, $subtotal, $available);
                        }
                    }
                } else {
                    $budget = \App\Models\BudgetAllocation::find($pr->budget_id);
                    if (!$budget || $budget->verification_status !== 'verified') {
                        throw new UnverifiedBudgetException($budget?->period_label ?? 'budget', $budget?->verification_status ?? 'pending');
                    }
                    if (!$this->budgetService->hasAvailable($budget, (float) $pr->estimated_total)) {
                        $available = (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent;
                        throw new InsufficientBudgetException($budget->period_label, (float) $pr->estimated_total, $available);
                    }
                }

                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);
            });
        } catch (UnverifiedBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Approval failed: ' . $e->getMessage(), 500);
        }

        foreach ($pr->details->pluck('location_id')->filter()->unique() as $locId) {
            RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
        }
        if (!$hasLineWarehouses && $pr->location_id) {
            RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');
        }

        $pr->load(self::WITH_RELATIONS);
        return $this->success($pr, 'Purchase request approved');
    }

    /**
     * Admin rejects a PR with a reason.
     */
    public function reject(Request $request, $id)
    {
        $pr = PurchaseRequest::find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isPending()) {
            return $this->error('Only pending purchase requests can be rejected', 422);
        }

        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $pr->update([
            'status_id'        => 15,
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        $targets = $pr->details()->whereNotNull('location_id')->distinct()->pluck('location_id');
        if ($targets->isEmpty() && $pr->location_id) { $targets = collect([$pr->location_id]); }
        foreach ($targets as $locId) {
            RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
        }

        $pr->load(self::WITH_RELATIONS);
        return $this->success($pr, 'Purchase request rejected');
    }

    /**
     * GET — Return pre-filled PO form data from an approved PR.
     * Each line includes quantity_already_ordered + quantity_remaining.
     * Also returns the suppliers list so the frontend can populate the supplier dropdown.
     */
    public function convert($id)
    {
        $pr = PurchaseRequest::with([
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
            'details.location',
            'location',
            'budget',
        ])->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isApproved()) {
            return $this->error('Only approved purchase requests can be converted to a PO', 403);
        }
        if ($pr->converted_to_po_id) {
            return $this->error('This purchase request has already been converted to a PO', 422);
        }

        $prId = $pr->pr_id;

        $prefilledLines = $pr->details->map(function ($d) use ($prId) {
            $alreadyOrdered = (int) PurchaseOrderDetail::where('product_id', $d->product_id)
                ->whereHas('purchaseOrder', fn ($q) => $q->where('pr_id', $prId))
                ->sum('quantity_ordered');

            $p  = $d->product;
            $ad = $p?->applianceDetail;
            $cd = $p?->installationMaterialDetail;

            return [
                'product_id'               => $d->product_id,
                'product_code'             => $p?->product_code,
                'product_name'             => $p?->product_name,
                'product_type'             => $p?->product_type,
                'is_serialized'            => $p?->isSerialized() ?? false,
                'brand_name'               => $ad?->brand?->brand_name,
                'capacity_rating'          => $ad?->capacity_rating,
                'variant'                  => $ad?->variant,
                'unit_name'                => $cd?->unit?->unit_name,
                'unit_abbreviation'        => $cd?->unit?->unit_abbreviation,
                'pieces_per_package'       => $cd?->pieces_per_package,
                'package_unit'             => $cd?->package_unit,
                'quantity_ordered'         => $d->quantity,
                'unit_price'               => $d->estimated_price,
                'subtotal'                 => $d->subtotal,
                'quantity_already_ordered' => $alreadyOrdered,
                'quantity_remaining'       => max(0, $d->quantity - $alreadyOrdered),
                'location_id'              => $d->location_id,
                'warehouse_name'           => $d->location?->location_name,
            ];
        });

        $suppliers = Supplier::select('supplier_id', 'supplier_name')
            ->orderBy('supplier_name')
            ->get();

        return $this->success([
            'pr'              => $pr,
            'prefilled_lines' => $prefilledLines,
            'suppliers'       => $suppliers,
            'pr_supplier_id'  => $pr->supplier_id,
        ], 'PR convert form data');
    }

    /**
     * Archive (soft-delete) a PR. PRs no longer hold budget reservations
     * (reservation is PO-anchored), so archiving a PR is a plain soft-delete.
     */
    public function destroy(Request $request, $id)
    {
        $pr = PurchaseRequest::find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }

        $pr->delete();

        $targets = $pr->details()->whereNotNull('location_id')->distinct()->pluck('location_id');
        if ($targets->isEmpty() && $pr->location_id) { $targets = collect([$pr->location_id]); }
        foreach ($targets as $locId) {
            RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
        }

        return $this->success(null, 'Purchase request archived');
    }

    /**
     * POST — Create a PO from an approved PR.
     */
    public function archived(Request $request)
    {
        $query = PurchaseRequest::onlyTrashed()->with(self::WITH_RELATIONS);

        if ($request->filled('search')) {
            $query->where('pr_number', 'like', '%' . $request->search . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived purchase requests retrieved'
        );
    }

    public function restore($id)
    {
        $pr = PurchaseRequest::onlyTrashed()->find($id);
        if (! $pr) {
            return $this->notFound('Archived purchase request not found');
        }
        $pr->restore();
        return $this->success($pr->fresh()->load(self::WITH_RELATIONS), 'Purchase request restored successfully');
    }

    public function forceDestroy($id)
    {
        $pr = PurchaseRequest::onlyTrashed()->find($id);
        if (! $pr) {
            return $this->notFound('Archived purchase request not found');
        }
        $pr->forceDelete();
        return $this->success(null, 'Purchase request permanently deleted');
    }

    public function convertStore(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details')->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isApproved() && (int) $pr->status_id !== 16) {
            return $this->error('Only approved purchase requests can be converted to a PO', 403);
        }
        if ($pr->converted_to_po_id) {
            return $this->error('This purchase request has already been converted to a PO', 422);
        }

        $hasLineWarehouses = $pr->details->whereNotNull('location_id')->isNotEmpty();

        try {
            $rules = [
                'expected_delivery_date'     => 'nullable|date',
                'payment_terms'              => 'nullable|string|max:50',
                'notes'                      => 'nullable|string',
                'references_note'            => 'nullable|string',
                'ship_to_name'               => 'nullable|string|max:255',
                'ship_to_address'            => 'nullable|string',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_ordered' => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
            ];
            if ($hasLineWarehouses) {
                $rules['details.*.location_id'] = 'required|exists:locations,location_id';
            } else {
                $rules['supplier_id'] = 'required|exists:suppliers,supplier_id';
            }
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        if ($hasLineWarehouses) {
            $prPairs = $pr->details->map(fn ($d) => $d->product_id . ':' . $d->location_id)->all();
            foreach ($validated['details'] as $line) {
                $pair = $line['product_id'] . ':' . $line['location_id'];
                if (!in_array($pair, $prPairs, true)) {
                    return $this->error('A converted line does not match this purchase request (product/warehouse mismatch).', 422);
                }
            }
        }

        try {
            $po = DB::transaction(function () use ($pr, $validated, $request, $hasLineWarehouses) {
                $mkLine = function ($d) {
                    $d['subtotal'] = $d['quantity_ordered'] * $d['unit_price'];
                    return $d;
                };
                $lines = collect($validated['details'])->map($mkLine);
                $grand = $lines->sum('subtotal');

                $supplierId = $hasLineWarehouses ? $pr->supplier_id : $validated['supplier_id'];
                // Header location_id is a compatibility "primary": the first line's
                // warehouse for the new shape, or the PR header for legacy.
                $headerLocationId = $hasLineWarehouses
                    ? (int) $lines->first()['location_id']
                    : (int) $pr->location_id;

                $po = PurchaseOrder::create([
                    'pr_id'                  => $pr->pr_id,
                    'supplier_id'            => $supplierId,
                    'location_id'            => $headerLocationId,
                    'po_number'              => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'order_date'             => today(),
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'payment_terms'          => $validated['payment_terms']    ?? null,
                    'notes'                  => $validated['notes']            ?? null,
                    'references_note'        => $validated['references_note']  ?? null,
                    'ship_to_name'           => $validated['ship_to_name']     ?? null,
                    'ship_to_address'        => $validated['ship_to_address']  ?? null,
                    'total_amount'           => $grand,
                    'grand_total'            => $grand,
                    'status_id'              => 7,
                    'created_by'             => $request->user()->user_id,
                ]);

                foreach ($lines as $line) {
                    PurchaseOrderDetail::create([
                        'po_id'            => $po->po_id,
                        'location_id'      => $hasLineWarehouses ? (int) $line['location_id'] : null,
                        'product_id'       => $line['product_id'],
                        'quantity_ordered' => $line['quantity_ordered'],
                        'unit_price'       => $line['unit_price'],
                        'subtotal'         => $line['subtotal'],
                    ]);
                }

                $pr->update(['converted_to_po_id' => $po->po_id]);

                $orderedByProdLoc = collect($validated['details'])->groupBy(fn ($d) =>
                    ($hasLineWarehouses ? $d['location_id'] : '_') . ':' . $d['product_id'])
                    ->map(fn ($rows) => collect($rows)->sum('quantity_ordered'));
                $isPartial = $pr->details->contains(function ($prDetail) use ($orderedByProdLoc, $hasLineWarehouses) {
                    $key = ($hasLineWarehouses ? $prDetail->location_id : '_') . ':' . $prDetail->product_id;
                    return ($orderedByProdLoc[$key] ?? 0) < (int) $prDetail->quantity;
                });
                $pr->update(['status_id' => $isPartial ? 16 : 14]);

                return $po;
            });

            foreach ($pr->details->pluck('location_id')->filter()->unique() as $locId) {
                RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
                RealtimeBroadcaster::purchaseOrderChanged((int) $locId, 'pr_converted');
            }
            if (!$hasLineWarehouses && $pr->location_id) {
                RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');
                RealtimeBroadcaster::purchaseOrderChanged((int) $pr->location_id, 'pr_converted');
            }

            $po->load(['supplier', 'location', 'status', 'purchaseRequest',
                'details.product.applianceDetail.brand', 'details.product.installationMaterialDetail.unit']);
            return $this->success($po, 'Purchase request converted to purchase order', 201);
        } catch (\Throwable $e) {
            return $this->error('Conversion failed: ' . $e->getMessage(), 500);
        }
    }
}
