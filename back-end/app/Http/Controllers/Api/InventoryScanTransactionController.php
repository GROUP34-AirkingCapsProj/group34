<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\Transfer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Single endpoint for Inventory Operation (BarcodeScan) engine: receiving, transfer, audit, issuance.
 */
class InventoryScanTransactionController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:191',
                'transaction_type'   => 'required|string|in:receiving,transfer,audit,issuance',
                'location_id'        => 'nullable|exists:locations,location_id',
                'from_location_id'   => 'nullable|exists:locations,location_id',
                'to_location_id'     => 'nullable|exists:locations,location_id',
                'quantity'           => 'nullable|integer|min:1',
                'po_id'              => 'nullable|exists:purchase_orders,po_id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $barcode = trim($validated['barcode']);
        $type = $validated['transaction_type'];
        $quantity = max(1, (int) ($validated['quantity'] ?? 1));

        $product = $this->resolveProductByBarcode($barcode);
        if (! $product) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Product not found for this barcode.',
            ], 'Rejected');
        }

        try {
            return match ($type) {
                'receiving' => $this->processReceiving($request, $product, $validated, $quantity),
                'transfer'  => $this->processTransfer($request, $product, $validated, $quantity),
                'audit'     => $this->processAudit($request, $product, (int) ($validated['location_id'] ?? 0), $barcode),
                'issuance'  => $this->processIssuance($request, $product, $validated, $quantity),
                default     => $this->error('Unsupported transaction type', 400),
            };
        } catch (\Throwable $e) {
            return $this->error('Scan transaction failed: '.$e->getMessage(), 500);
        }
    }

    private function resolveProductByBarcode(string $raw): ?Product
    {
        $digitsOnly = preg_replace('/\D/', '', $raw);
        $variants = array_values(array_unique(array_filter([
            $raw,
            strtoupper($raw),
            strtolower($raw),
            preg_replace('/\s+/', '', $raw),
            $digitsOnly !== '' ? $digitsOnly : null,
        ])));

        foreach ($variants as $v) {
            $norm = strtolower(trim($v));
            if ($norm === '') {
                continue;
            }
            $p = Product::query()
                ->where(function ($q) use ($norm) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(barcode, \'\'))) = ?', [$norm])
                        ->orWhereRaw('LOWER(TRIM(COALESCE(product_code, \'\'))) = ?', [$norm]);
                })
                ->first();
            if ($p) {
                return $p;
            }
        }

        foreach ($variants as $v) {
            $serial = ItemSerial::query()
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($v))])
                ->first();
            if ($serial && $serial->product_id) {
                return Product::query()->where('product_id', $serial->product_id)->first();
            }
        }

        if (strlen($digitsOnly) === 12 && ctype_digit($digitsOnly)) {
            $ean = $this->ean13From12($digitsOnly);
            if ($ean) {
                return Product::query()
                    ->where(function ($q) use ($ean) {
                        $q->where('barcode', $ean)->orWhere('product_code', $ean);
                    })
                    ->first();
            }
        }

        return null;
    }

    private function ean13From12(string $twelve): ?string
    {
        if (! preg_match('/^\d{12}$/', $twelve)) {
            return null;
        }
        $sum = 0;
        foreach (str_split($twelve) as $i => $ch) {
            $d = (int) $ch;
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $twelve.$check;
    }

    private function processReceiving(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $locationId = (int) ($validated['location_id'] ?? 0);
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a receiving location.',
            ], 'Rejected');
        }

        if (! empty($validated['po_id'])) {
            $poId = (int) $validated['po_id'];
            $onPo = PurchaseOrderDetail::query()
                ->where('po_id', $poId)
                ->where('product_id', $product->product_id)
                ->exists();
            if (! $onPo) {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'This product is not on the selected purchase order.',
                ], 'Rejected');
            }
        }

        DB::beginTransaction();
        try {
            $inv = Inventory::firstOrCreate(
                [
                    'location_id' => $locationId,
                    'product_id'  => $product->product_id,
                ],
                [
                    'quantity_on_hand'   => 0,
                    'available_quantity' => 0,
                    'reorder_level'      => 0,
                ]
            );
            $inv->quantity_on_hand = (int) $inv->quantity_on_hand + $quantity;
            $inv->available_quantity = (int) $inv->available_quantity + $quantity;
            $inv->save();

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            return $this->success([
                'action'    => 'applied',
                'product'   => $fresh,
                'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'  => [],
            ], 'Receiving posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processTransfer(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $fromLocationId = (int) ($validated['from_location_id'] ?? 0);
        $toLocationId = (int) ($validated['to_location_id'] ?? 0);
        if ($fromLocationId <= 0 || $toLocationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select source and destination locations.',
            ], 'Rejected');
        }
        if ($fromLocationId === $toLocationId) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'From and To locations must be different.',
            ], 'Rejected');
        }

        DB::beginTransaction();
        try {
            $fromInventory = Inventory::where('location_id', $fromLocationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if (! $fromInventory) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'No inventory at the source location for this product.',
                ], 'Rejected');
            }

            $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
            if ($available < $quantity) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => "Insufficient stock at source. Available: {$available}, requested: {$quantity}.",
                ], 'Rejected');
            }

            $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $quantity);
            $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $quantity);
            $fromInventory->save();

            $toInventory = Inventory::firstOrCreate(
                [
                    'location_id' => $toLocationId,
                    'product_id'  => $product->product_id,
                ],
                [
                    'quantity_on_hand'   => 0,
                    'available_quantity' => 0,
                    'reorder_level'      => 0,
                ]
            );
            $toInventory->quantity_on_hand = (int) $toInventory->quantity_on_hand + $quantity;
            $toInventory->available_quantity = (int) $toInventory->available_quantity + $quantity;
            $toInventory->save();

            $transferNumber = 'TR-SCAN-'.str_replace('.', '', uniqid('', true));
            $uid = $request->user()?->user_id;

            $transferPayload = [
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $toLocationId,
                'transfer_number'  => $transferNumber,
                'transfer_date'    => now(),
                'requested_by'     => $uid,
                'received_by'      => $uid,
                'status_id'        => $this->transferCompletedStatusId(),
            ];
            if (Schema::hasColumn('transfers', 'date')) {
                $transferPayload['date'] = now();
            }

            $transfer = Transfer::create($transferPayload);

            $detailPayload = [
                'product_id'           => $product->product_id,
                'quantity_transferred' => $quantity,
            ];
            if (Schema::hasColumn('transfer_details', 'quantity_received')) {
                $detailPayload['quantity_received'] = $quantity;
            }
            $transfer->details()->create($detailPayload);

            if (Schema::hasColumn('transfers', 'total_quantity_transferred')) {
                $transfer->update(['total_quantity_transferred' => $quantity]);
            }
            if (Schema::hasColumn('transfers', 'total_quantity_received')) {
                $transfer->update(['total_quantity_received' => $quantity]);
            }

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            return $this->success([
                'action'    => 'applied',
                'product'   => $fresh,
                'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'  => [],
                'reference' => $transferNumber,
            ], 'Transfer posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processAudit(Request $request, Product $product, int $locationId, string $barcode): \Illuminate\Http\JsonResponse
    {
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a location before audit scan.',
            ], 'Rejected');
        }

        $inv = Inventory::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->with(['location'])
            ->first();

        $stock = $inv ? (int) ($inv->available_quantity ?? $inv->quantity_on_hand ?? 0) : 0;
        $locationName = $inv?->location?->location_name
            ?? \App\Models\Location::query()->where('location_id', $locationId)->value('location_name')
            ?? '—';

        $uid = $request->user()?->user_id;
        if ($uid) {
            ActivityLog::create([
                'user_id'       => $uid,
                'activity_type' => 'audit_scan',
                'module'        => 'inventory_operation',
                'description'   => sprintf('Audit scan barcode=%s product_id=%s location_id=%s stock=%s', $barcode, $product->product_id, $locationId ?: '—', $stock),
            ]);
        }

        $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

        return $this->success([
            'action' => 'applied',
            'product' => $fresh,
            'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
            'warnings' => [],
            'audit' => [
                'location_id'   => $locationId ?: null,
                'location_name' => $locationName,
                'stock_on_hand' => $stock,
            ],
        ], 'Audit logged');
    }

    private function processIssuance(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $locationId = (int) ($validated['location_id'] ?? 0);
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a location for issuance.',
            ], 'Rejected');
        }

        DB::beginTransaction();
        try {
            $fromInventory = Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if (! $fromInventory) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'No inventory record at this location for this product.',
                ], 'Rejected');
            }

            $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
            if ($available < $quantity) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => "Insufficient stock. Available: {$available}, requested: {$quantity}.",
                ], 'Rejected');
            }

            $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $quantity);
            $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $quantity);
            $fromInventory->save();

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            return $this->success([
                'action'    => 'applied',
                'product'   => $fresh,
                'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'  => [],
            ], 'Issuance posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** @return array<int, mixed> */
    private function inventoryRowsForProduct(int $productId): array
    {
        return Inventory::where('product_id', $productId)
            ->with(['location'])
            ->get()
            ->all();
    }

    private function transferCompletedStatusId(): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where(function ($q) {
                $q->whereRaw('LOWER(status_name) LIKE ?', ['%complete%'])
                    ->orWhereRaw('LOWER(status_name) LIKE ?', ['%received%']);
            })
            ->value('status_id');

        if ($id !== null) {
            return (int) $id;
        }

        return DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->value('status_id');
    }
}
