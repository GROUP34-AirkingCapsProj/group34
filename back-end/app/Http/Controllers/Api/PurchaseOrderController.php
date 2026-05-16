<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
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
        'receivedBy',
        'status',
        'purchaseRequest',
        'details.product.applianceDetail.brand',
        'details.product.applianceDetail.modelLookup',
        'details.product.consumableDetail.unit',
        'details.approvedBy',
    ];

    public function index(Request $request)
    {
        try {
            $query = PurchaseOrder::with(self::WITH_RELATIONS);

            if ($request->has('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('po_number', 'like', '%' . $request->search . '%');
            }

            $perPage   = $request->get('per_page', 20);
            $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);
            $paginated->getCollection()->each(function ($po) {
                $po->details->transform(fn ($d) => $this->transformPoDetail($d));
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
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            $authUser = $request->user();
            if ($authUser) {
                $validated['created_by'] = $authUser->user_id;
            }

            DB::beginTransaction();

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

            $po->details->transform(fn ($d) => $this->transformPoDetail($d));

            return $this->success($po, 'Purchase order retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Purchase order not found', 404);
        }
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
                'details'                    => 'sometimes|array|min:1',
                'details.*.product_id'       => 'required_with:details|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required_with:details|integer|min:1',
                'details.*.unit_price'       => 'required_with:details|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            DB::beginTransaction();

            $header = Arr::except($validated, ['details']);
            if (!empty($header)) {
                $wasCompleted = $po->status_id !== 11
                    && isset($validated['status_id'])
                    && (int) $validated['status_id'] === 11;

                $po->update($header);

                if ($wasCompleted && $po->pr_id) {
                    $pr = $po->purchaseRequest;
                    if ($pr && $pr->budget_id) {
                        BudgetAllocation::where('budget_id', $pr->budget_id)
                            ->increment('spent', $po->total_amount);
                        BudgetAllocation::where('budget_id', $pr->budget_id)
                            ->decrement('committed', $po->total_amount);
                    }
                }
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

    public function destroy($id)
    {
        try {
            $po = PurchaseOrder::findOrFail($id);
            $po->delete();
            return $this->success(null, 'Purchase order deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete purchase order: ' . $e->getMessage(), 500);
        }
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
                'applianceDetail.modelLookup',
                'consumableDetail.unit',
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
                'unit_name'          => $p->consumableDetail?->unit?->unit_name,
                'unit_abbreviation'  => $p->consumableDetail?->unit?->unit_abbreviation,
                'pieces_per_package' => $p->consumableDetail?->pieces_per_package,
                'package_unit'       => $p->consumableDetail?->package_unit,
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
            'details.product.consumableDetail.unit',
            'supplier', 'location', 'status',
            'purchaseRequest',
            'createdBy', 'approvedBy', 'receivedBy',
        ])->orderBy('created_at', 'desc')->get();

        $filename = 'purchase-orders-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $csvColumns = [
            'PO Number', 'PR Number', 'Order Date', 'Expected Delivery',
            'Location', 'Supplier', 'Status',
            'Created By', 'Approved By', 'Received By',
            'Line #', 'Product Code', 'Product Name', 'Type',
            'Brand / Unit', 'Capacity / Pkg Unit', 'Pieces/Package',
            'Qty Ordered', 'Unit Price', 'Subtotal',
            'Total Amount', 'Grand Total',
        ];

        $callback = function () use ($pos, $csvColumns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $csvColumns);

            foreach ($pos as $po) {
                $createdBy  = $po->createdBy
                    ? trim($po->createdBy->first_name  . ' ' . $po->createdBy->last_name)  : '';
                $approvedBy = $po->approvedBy
                    ? trim($po->approvedBy->first_name . ' ' . $po->approvedBy->last_name) : '';
                $receivedBy = $po->receivedBy
                    ? trim($po->receivedBy->first_name . ' ' . $po->receivedBy->last_name) : '';

                if ($po->details->isEmpty()) {
                    fputcsv($handle, [
                        $po->po_number,
                        $po->purchaseRequest?->pr_number ?? '',
                        $po->order_date,
                        $po->expected_delivery_date ?? '',
                        $po->location?->location_name  ?? '',
                        $po->supplier?->supplier_name  ?? '',
                        $po->status?->status_name      ?? '',
                        $createdBy, $approvedBy, $receivedBy,
                        '', '', '', '', '', '', '', '', '', '',
                        number_format((float) $po->total_amount, 2),
                        number_format((float) $po->grand_total,  2),
                    ]);
                    continue;
                }

                foreach ($po->details as $i => $d) {
                    $p  = $d->product;
                    $ad = $p?->applianceDetail;
                    $cd = $p?->consumableDetail;

                    fputcsv($handle, [
                        $po->po_number,
                        $po->purchaseRequest?->pr_number ?? '',
                        $po->order_date,
                        $po->expected_delivery_date ?? '',
                        $po->location?->location_name  ?? '',
                        $po->supplier?->supplier_name  ?? '',
                        $po->status?->status_name      ?? '',
                        $createdBy, $approvedBy, $receivedBy,
                        $i + 1,
                        $p?->product_code ?? '',
                        $p?->product_name ?? '',
                        $p ? ucfirst($p->product_type) : '',
                        $ad?->brand?->brand_name ?? $cd?->unit?->unit_name ?? '',
                        $ad?->capacity_rating    ?? $cd?->package_unit     ?? '',
                        $cd?->pieces_per_package ?? '',
                        $d->quantity_ordered,
                        number_format((float) $d->unit_price, 2),
                        number_format((float) $d->subtotal,   2),
                        $i === 0 ? number_format((float) $po->total_amount, 2) : '',
                        $i === 0 ? number_format((float) $po->grand_total,  2) : '',
                    ]);
                }
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function transformPoDetail($detail): array
    {
        $p  = $detail->product;
        $ad = $p?->applianceDetail;
        $cd = $p?->consumableDetail;

        return [
            'po_detail_id'       => $detail->po_detail_id,
            'po_id'              => $detail->po_id,
            'product_id'         => $detail->product_id,
            'quantity_ordered'   => $detail->quantity_ordered,
            'unit_price'         => $detail->unit_price,
            'subtotal'           => $detail->subtotal,
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
}
