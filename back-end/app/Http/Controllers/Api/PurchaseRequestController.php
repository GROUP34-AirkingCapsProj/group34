<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\Supplier;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseRequestController extends Controller
{
    use ApiResponse;

    private const WITH_RELATIONS = [
        'location',
        'status',
        'requester',
        'approver',
        'budget',
        'convertedPO',
        'details.product.applianceDetail.brand',
        'details.product.applianceDetail.modelLookup',
        'details.product.consumableDetail.unit',
    ];

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

    public function export(Request $request)
    {
        $prs = PurchaseRequest::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'location',
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
            'Purpose', 'Status', 'Requested By', 'Approved By', 'Budget Period',
            'Line #', 'Product Code', 'Product Name', 'Type',
            'Brand / Unit', 'Capacity / Pkg Unit', 'Pieces/Package',
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

                if ($pr->details->isEmpty()) {
                    fputcsv($handle, [
                        $pr->pr_number,
                        $pr->request_date,
                        $pr->needed_by_date ?? '',
                        $pr->location?->location_name ?? '',
                        $pr->purpose ?? '',
                        $pr->status?->status_name ?? '',
                        $requestedBy, $approvedBy,
                        $pr->budget?->period_label ?? '',
                        '', '', '', '', '', '', '', '', '', '', '',
                        number_format((float) $pr->estimated_total, 2),
                    ]);
                    continue;
                }

                foreach ($pr->details as $i => $d) {
                    $p  = $d->product;
                    $ad = $p?->applianceDetail;
                    $cd = $p?->consumableDetail;

                    fputcsv($handle, [
                        $pr->pr_number,
                        $pr->request_date,
                        $pr->needed_by_date ?? '',
                        $pr->location?->location_name ?? '',
                        $pr->purpose ?? '',
                        $pr->status?->status_name ?? '',
                        $requestedBy, $approvedBy,
                        $pr->budget?->period_label ?? '',
                        $i + 1,
                        $p?->product_code ?? '',
                        $p?->product_name ?? '',
                        $p ? ucfirst($p->product_type) : '',
                        $ad?->brand?->brand_name ?? $cd?->unit?->unit_name   ?? '',
                        $ad?->capacity_rating    ?? $cd?->package_unit       ?? '',
                        $cd?->pieces_per_package ?? '',
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
        $cd = $p?->consumableDetail;

        return [
            'pr_detail_id'       => $detail->pr_detail_id,
            'pr_id'              => $detail->pr_id,
            'product_id'         => $detail->product_id,
            'quantity'           => $detail->quantity,
            'estimated_price'    => $detail->estimated_price,
            'subtotal'           => $detail->subtotal,
            'notes'              => $detail->notes,
            // Product shared
            'product_code'       => $p?->product_code,
            'product_name'       => $p?->product_name,
            'product_type'       => $p?->product_type,
            'is_serialized'      => $p?->isSerialized() ?? false,
            // Appliance (null for consumables)
            'brand_name'         => $ad?->brand?->brand_name,
            'capacity_rating'    => $ad?->capacity_rating,
            'variant'            => $ad?->variant,
            // Consumable (null for appliances)
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
                'location_id'               => 'required|exists:locations,location_id',
                'budget_id'                 => 'nullable|exists:budget_allocations,budget_id',
                'needed_by_date'            => 'required|date|after:today',
                'purpose'                   => 'nullable|string',
                'details'                   => 'required|array|min:1',
                'details.*.product_id'      => 'required|exists:products,product_id',
                'details.*.quantity'        => 'required|integer|min:1',
                'details.*.estimated_price' => 'required|numeric|min:0',
                'details.*.notes'           => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $pr = DB::transaction(function () use ($validated, $request) {
                $estimatedTotal = collect($validated['details'])
                    ->sum(fn($d) => $d['quantity'] * $d['estimated_price']);

                $pr = PurchaseRequest::create([
                    'location_id'     => $validated['location_id'],
                    'budget_id'       => $validated['budget_id'] ?? null,
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
                        'quantity'        => $detail['quantity'],
                        'estimated_price' => $detail['estimated_price'],
                        'subtotal'        => $detail['quantity'] * $detail['estimated_price'],
                        'notes'           => $detail['notes'] ?? null,
                    ]);
                }

                return $pr;
            });

            $pr->load(self::WITH_RELATIONS);
            return $this->success($pr, 'Purchase request submitted', 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin approves a PR and commits the budget.
     */
    public function approve(Request $request, $id)
    {
        $pr = PurchaseRequest::find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isPending()) {
            return $this->error('Only pending purchase requests can be approved', 422);
        }

        try {
            DB::transaction(function () use ($pr, $request) {
                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);

                if ($pr->budget_id) {
                    BudgetAllocation::where('budget_id', $pr->budget_id)
                        ->increment('committed', $pr->estimated_total);
                }
            });

            $pr->load(self::WITH_RELATIONS);
            return $this->success($pr, 'Purchase request approved');
        } catch (\Throwable $e) {
            return $this->error('Approval failed: ' . $e->getMessage(), 500);
        }
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
            'details.product.applianceDetail.modelLookup',
            'details.product.consumableDetail.unit',
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
            $cd = $p?->consumableDetail;

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
            ];
        });

        $suppliers = Supplier::select('supplier_id', 'supplier_name')
            ->orderBy('supplier_name')
            ->get();

        return $this->success([
            'pr'              => $pr,
            'prefilled_lines' => $prefilledLines,
            'suppliers'       => $suppliers,
        ], 'PR convert form data');
    }

    /**
     * POST — Create a PO from an approved PR.
     */
    public function convertStore(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details')->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isApproved()) {
            return $this->error('Only approved purchase requests can be converted to a PO', 403);
        }
        if ($pr->converted_to_po_id) {
            return $this->error('This purchase request has already been converted to a PO', 422);
        }

        try {
            $validated = $request->validate([
                'supplier_id'                    => 'required|exists:suppliers,supplier_id',
                'expected_delivery_date'         => 'nullable|date',
                'payment_terms'                  => 'nullable|string|max:50',
                'notes'                          => 'nullable|string',
                'references_note'                => 'nullable|string',
                'verified_by'                    => 'nullable|exists:users,user_id',
                'ship_to_name'                   => 'nullable|string|max:255',
                'ship_to_address'                => 'nullable|string',
                'details'                        => 'required|array|min:1',
                'details.*.product_id'           => 'required|exists:products,product_id',
                'details.*.quantity_ordered'      => 'required|integer|min:1',
                'details.*.unit_price'           => 'required|numeric|min:0',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $po = DB::transaction(function () use ($pr, $validated, $request) {
                $lines = array_map(function ($d) {
                    $d['subtotal'] = $d['quantity_ordered'] * $d['unit_price'];
                    return $d;
                }, $validated['details']);

                $grandTotal = collect($lines)->sum('subtotal');

                $po = PurchaseOrder::create([
                    'pr_id'                  => $pr->pr_id,
                    'supplier_id'            => $validated['supplier_id'],
                    'location_id'            => $pr->location_id,
                    'po_number'              => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'order_date'             => today(),
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'payment_terms'          => $validated['payment_terms']    ?? null,
                    'notes'                  => $validated['notes']            ?? null,
                    'references_note'        => $validated['references_note']  ?? null,
                    'verified_by'            => $validated['verified_by']      ?? null,
                    'ship_to_name'           => $validated['ship_to_name']     ?? null,
                    'ship_to_address'        => $validated['ship_to_address']  ?? null,
                    'total_amount'           => $grandTotal,
                    'grand_total'            => $grandTotal,
                    'status_id'              => 7,
                    'created_by'             => $request->user()->user_id,
                ]);

                foreach ($lines as $line) {
                    PurchaseOrderDetail::create([
                        'po_id'           => $po->po_id,
                        'product_id'      => $line['product_id'],
                        'quantity_ordered' => $line['quantity_ordered'],
                        'unit_price'      => $line['unit_price'],
                        'subtotal'        => $line['subtotal'],
                    ]);
                }

                $pr->update(['converted_to_po_id' => $po->po_id]);

                $orderedMap = collect($lines)->keyBy('product_id');
                $isPartial  = $pr->details->contains(function ($prDetail) use ($orderedMap) {
                    $poLine = $orderedMap->get($prDetail->product_id);
                    return !$poLine || $poLine['quantity_ordered'] < $prDetail->quantity;
                });

                $pr->update(['status_id' => $isPartial ? 16 : 14]);

                return $po;
            });

            $po->load([
                'supplier', 'location', 'status', 'purchaseRequest',
                'details.product.applianceDetail.brand',
                'details.product.applianceDetail.modelLookup',
                'details.product.consumableDetail.unit',
            ]);
            return $this->success($po, 'Purchase request converted to purchase order', 201);
        } catch (\Throwable $e) {
            return $this->error('Conversion failed: ' . $e->getMessage(), 500);
        }
    }
}
