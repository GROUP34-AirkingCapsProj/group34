<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TblTransferTracking;
use App\Models\Inventory;
use App\Services\TransferService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ISTController extends Controller
{
    use ApiResponse;

    private const WITH_RELATIONS = [
        'fromLocation',
        'toLocation',
        'preparedBy',
        'approvedBy',
        'requestedBy',
        'status',
        'details.product',
        'transferRequest',
    ];

    private function statusId(string $name): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');
        return $id !== null ? (int) $id : null;
    }

    private function isAdminOrWarehouse(Request $request): bool
    {
        $role = $request->user()->role?->role_name ?? '';
        return in_array($role, ['admin', 'warehouse_personnel', 'inventory_analyst'], true);
    }

    /** Returns a 403 JsonResponse if the caller may not act on this IST's destination, else null. */
    private function ensureReceiveLocation(Request $request, Transfer $ist): ?\Illuminate\Http\JsonResponse
    {
        $user      = $request->user();
        $role      = $user->role?->role_name ?? '';
        $isAdmin   = in_array($role, ['admin', 'inventory_analyst'], true);
        $userLocId = $user->enforced_location_id ?? $user->location_id;

        if (!$isAdmin && $userLocId && (int) $userLocId !== (int) $ist->to_location_id) {
            return $this->error('You are not authorized to receive items at this destination.', 403);
        }
        return null;
    }

    /**
     * List all ISTs (transfer_request_id IS NOT NULL) for current user's location.
     */
    public function index(Request $request)
    {
        try {
            $user    = $request->user();
            $role    = $user->role?->role_name ?? '';
            $isAdmin = in_array($role, ['admin', 'inventory_analyst'], true);

            $query = Transfer::with(array_merge(self::WITH_RELATIONS, ['transferTracking.recordedBy']))
                ->whereNotNull('transfer_request_id');

            if (!$isAdmin) {
                $locId = $user->enforced_location_id ?? $user->location_id;
                if ($locId) {
                    $query->where(function ($q) use ($locId) {
                        $q->where('from_location_id', $locId)
                          ->orWhere('to_location_id', $locId);
                    });
                }
            }

            if ($request->filled('status_id')) {
                $query->where('status_id', $request->integer('status_id'));
            }
            if ($request->filled('search')) {
                $query->where('transfer_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->integer('per_page', 20);
            return $this->paginated(
                $query->orderBy('created_at', 'desc')->paginate($perPage),
                'ISTs retrieved'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve ISTs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return pre-fill data for IST creation from an approved request.
     * Includes quantity_requested, stock_at_source, and request header details.
     */
    public function prepareFromRequest($requestId)
    {
        try {
            $approvedId = $this->statusId('Approved');

            $req = Transfer::with(['fromLocation', 'toLocation', 'approvedBy', 'requestedBy', 'details.product'])
                ->whereNull('transfer_request_id')
                ->where('status_id', $approvedId)
                ->findOrFail($requestId);

            // Attach stock_at_source per line
            foreach ($req->details as $d) {
                $d->stock_at_source = (int) Inventory::where('location_id', $req->from_location_id)
                    ->where('product_id', $d->product_id)
                    ->value('quantity_on_hand') ?? 0;
            }

            return $this->success($req, 'Request data retrieved for IST creation');
        } catch (\Exception $e) {
            return $this->error('Approved request not found', 404);
        }
    }

    /**
     * Store a new IST from an approved transfer request.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'transfer_request_id'                       => 'required|exists:transfers,transfer_id',
                'transfer_date'                             => 'required|date',
                'products'                                  => 'required|array|min:1',
                'products.*.transfer_detail_id'             => 'required|integer',
                'products.*.quantity_to_transfer'           => 'required|integer|min:0',
            ]);

            $approvedId = $this->statusId('Approved');

            // Load the original approved request
            $req = Transfer::with('details')
                ->whereNull('transfer_request_id')
                ->where('status_id', $approvedId)
                ->findOrFail($validated['transfer_request_id']);

            // Validate quantities don't exceed approved amounts
            $detailMap = $req->details->keyBy('transfer_detail_id');
            foreach ($validated['products'] as $row) {
                $detailId = (int) $row['transfer_detail_id'];
                $detail   = $detailMap->get($detailId);
                if (!$detail) {
                    return $this->error("Transfer detail ID {$detailId} does not belong to this request.", 422);
                }
                if ((int) $row['quantity_to_transfer'] > (int) $detail->quantity_requested) {
                    return $this->error(
                        "Quantity for {$detail->product_id} exceeds approved amount of {$detail->quantity_requested}.",
                        422
                    );
                }
            }

            // Filter to lines with qty > 0
            $activeLines = array_filter($validated['products'], fn ($r) => (int) $r['quantity_to_transfer'] > 0);
            if (empty($activeLines)) {
                return $this->error('At least one product must have a quantity greater than 0.', 422);
            }

            $user = $request->user();
            $username = $user->username ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: "user#{$user->user_id}";

            DB::beginTransaction();

            $ist = Transfer::create([
                'transfer_request_id'        => $req->transfer_id,
                'from_location_id'           => $req->from_location_id,
                'to_location_id'             => $req->to_location_id,
                'transfer_number'            => 'IST-PENDING-' . uniqid(),
                'transfer_date'              => $validated['transfer_date'],
                'prepared_by'                => $user->user_id,
                'approved_by'                => $req->approved_by,
                'status_id'                  => $approvedId,
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $istNumber = 'IST-' . date('Ymd') . '-' . str_pad((string) $ist->transfer_id, 4, '0', STR_PAD_LEFT);
            $ist->transfer_number = $istNumber;
            $ist->save();

            foreach ($activeLines as $row) {
                $detail = $detailMap->get((int) $row['transfer_detail_id']);
                $ist->details()->create([
                    'product_id'           => $detail->product_id,
                    'quantity_requested'   => (int) $row['quantity_to_transfer'],
                    'quantity_transferred' => 0,
                    'quantity_scanned'     => 0,
                    'quantity_received'    => 0,
                ]);
            }

            // Tracking on the IST itself
            TblTransferTracking::create([
                'transfer_id' => $ist->transfer_id,
                'location_id' => $req->from_location_id,
                'status_note' => "IST {$istNumber} created by {$username}",
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            // Tracking on the original request
            TblTransferTracking::create([
                'transfer_id' => $req->transfer_id,
                'location_id' => $req->from_location_id,
                'status_note' => "IST {$istNumber} created from this request",
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            DB::commit();

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $user->user_id,
                    'action'     => 'ist_created',
                    'table_name' => 'transfers',
                    'record_id'  => $ist->transfer_id,
                    'old_values' => null,
                    'new_values' => [
                        'ist_number'          => $istNumber,
                        'transfer_request_id' => $req->transfer_id,
                        'from_location_id'    => $req->from_location_id,
                        'to_location_id'      => $req->to_location_id,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $this->success(
                $ist->load(self::WITH_RELATIONS),
                'IST created successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create IST: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return a single IST with full relations, including per-product serial numbers.
     */
    public function show($id)
    {
        try {
            $ist = Transfer::with(array_merge(self::WITH_RELATIONS, ['transferTracking.recordedBy']))
                ->whereNotNull('transfer_request_id')
                ->findOrFail($id);

            // Attach serialized item serial numbers per detail
            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            return $this->success($ist, 'IST retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found', 404);
        }
    }

    /**
     * Return IST data for the receive-confirmation form (only In Transit ISTs).
     */
    public function receiveForm($id)
    {
        try {
            $inTransitId = $this->statusId('In Transit');
            $ist = Transfer::with(array_merge(self::WITH_RELATIONS, ['transferTracking.recordedBy']))
                ->whereNotNull('transfer_request_id')
                ->where('status_id', $inTransitId)
                ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            return $this->success($ist, 'IST receive form data retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found or not In Transit', 404);
        }
    }

    /**
     * Confirm receipt: update inventory at destination, set status to Received.
     */
    public function receiveStore(Request $request, $id, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->findOrFail($id);

            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }

            $user   = $request->user();
            $result = $service->receive($ist, $user->user_id);

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'ist_received',
                    'table_name' => 'transfers',
                    'record_id'  => $result->transfer_id,
                    'old_values' => ['status' => 'In Transit'],
                    'new_values' => ['status' => 'Received', 'total_quantity_received' => (int) $result->total_quantity_received],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) { report($e); }

            return $this->success([
                'transfer_number'         => $result->transfer_number,
                'status'                  => 'Received',
                'total_quantity_received' => (int) $result->total_quantity_received,
            ], 'Transfer received successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm receipt: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Record a receive-side barcode scan against an In Transit IST.
     */
    public function receiveScan(Request $request, $transferId, TransferService $service)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:255',
                'transfer_detail_id' => 'required|integer',
                'quantity'           => 'sometimes|integer|min:1',
            ]);

            $ist = Transfer::whereNotNull('transfer_request_id')->findOrFail($transferId);

            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }

            $user   = $request->user();
            $result = $service->recordReceiveScan(
                $ist,
                $validated['barcode'],
                (int) $validated['transfer_detail_id'],
                $user->user_id,
                (int) ($validated['quantity'] ?? 1)
            );

            return $this->success($result, 'Receive scan recorded');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Receive scan failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove a receive-side scan (undo before confirmation).
     */
    public function removeReceiveScan(Request $request, $transferId, $scanId, TransferService $service)
    {
        try {
            $ist = Transfer::whereNotNull('transfer_request_id')->findOrFail($transferId);
            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }
            $service->removeReceiveScan($ist, (int) $scanId);
            return $this->success(null, 'Receive scan removed');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to remove receive scan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return current scan progress for an IST (used to restore UI state across refreshes).
     */
    public function scanStatus($transferId)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->findOrFail($transferId);

            $scanHistory = \App\Models\BarcodeScan::where('transfer_id', $transferId)
                ->with('product')
                ->orderBy('created_at', 'asc')
                ->get();

            return $this->success([
                'details'      => $ist->details,
                'scan_history' => $scanHistory,
            ], 'Scan status retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found', 404);
        }
    }

    /**
     * Record a barcode scan against an approved IST.
     */
    public function scan(Request $request, $transferId)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:255',
                'transfer_detail_id' => 'required|integer',
                'quantity'           => 'sometimes|integer|min:1',
            ]);

            $approvedId = $this->statusId('Approved');
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->where('status_id', $approvedId)
                ->findOrFail($transferId);

            $detail = $ist->details->firstWhere('transfer_detail_id', (int) $validated['transfer_detail_id']);
            if (!$detail) {
                return $this->error('Transfer detail not found on this IST.', 422);
            }

            $isSerialized = $detail->product?->product_type === 'appliance';
            $qty          = $isSerialized ? 1 : (int) ($validated['quantity'] ?? 1);
            $currentScanned = (int) $detail->quantity_scanned;
            $target         = (int) $detail->quantity_requested;

            if ($currentScanned >= $target) {
                return $this->error(
                    "This product is already fully scanned ({$currentScanned}/{$target}).", 422
                );
            }
            if ($currentScanned + $qty > $target) {
                $remaining = $target - $currentScanned;
                return $this->error(
                    "Only {$remaining} more unit(s) needed. Cannot scan {$qty}.", 422
                );
            }

            // Duplicate barcode guard
            $dupe = \App\Models\BarcodeScan::where('transfer_id', $transferId)
                ->where('barcode', $validated['barcode'])
                ->exists();
            if ($dupe) {
                return $this->error(
                    "Barcode '{$validated['barcode']}' has already been scanned for this IST.", 422
                );
            }

            $user = $request->user();
            DB::beginTransaction();

            $detail->increment('quantity_scanned', $qty);
            $detail->refresh();

            $scan = \App\Models\BarcodeScan::create([
                'user_id'     => $user->user_id,
                'product_id'  => $detail->product_id,
                'transfer_id' => (int) $transferId,
                'barcode'     => $validated['barcode'],
                'scan_mode'   => 'transfer',
                'scanned_at'  => now(),
            ]);

            if ($isSerialized) {
                \App\Models\ItemSerial::where('serial_number', $validated['barcode'])
                    ->update(['transfer_id' => (int) $transferId]);
            }

            DB::commit();

            $ist->load('details');
            $fullyScanned = $ist->details->every(
                fn ($d) => (int) $d->quantity_scanned >= (int) $d->quantity_requested
            );

            return $this->success([
                'scan_id'            => $scan->id,
                'product_name'       => $detail->product?->product_name ?? "Product #{$detail->product_id}",
                'quantity_scanned'   => $qty,
                'total_scanned'      => (int) $detail->quantity_scanned,
                'quantity_requested' => $target,
                'remaining'          => $target - (int) $detail->quantity_scanned,
                'ist_fully_scanned'  => $fullyScanned,
            ], 'Scan recorded');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Scan failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove a single scan — decrements quantity_scanned and deletes the barcode_scans row.
     */
    public function removeScan(Request $request, $transferId, $scanId)
    {
        try {
            $approvedId = $this->statusId('Approved');
            $ist = Transfer::with('details')
                ->whereNotNull('transfer_request_id')
                ->where('status_id', $approvedId)
                ->findOrFail($transferId);

            $scan = \App\Models\BarcodeScan::where('id', $scanId)
                ->where('transfer_id', $transferId)
                ->firstOrFail();

            DB::beginTransaction();

            $detail = $ist->details->firstWhere('product_id', $scan->product_id);
            if ($detail && (int) $detail->quantity_scanned > 0) {
                $detail->decrement('quantity_scanned', 1);
            }

            \App\Models\ItemSerial::where('transfer_id', $transferId)
                ->where('serial_number', $scan->barcode)
                ->update(['transfer_id' => null]);

            $scan->delete();

            DB::commit();

            return $this->success(null, 'Scan removed');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to remove scan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Confirm dispatch: set IST to In Transit, deduct inventory, record tracking.
     */
    public function confirmDispatch(Request $request, $transferId, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->findOrFail($transferId);

            $result = $service->dispatch($ist, $request->user()?->user_id);

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'ist_dispatched',
                    'table_name' => 'transfers',
                    'record_id'  => $result->transfer_id,
                    'old_values' => ['status' => 'Approved'],
                    'new_values' => ['status' => 'In Transit', 'total_quantity_transferred' => (int) $result->total_quantity_transferred],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) { report($e); }

            return $this->success([
                'transfer_number'  => $result->transfer_number,
                'status'           => 'In Transit',
                'total_dispatched' => (int) $result->total_quantity_transferred,
            ], 'IST dispatched successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm dispatch: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return receipt data (JSON) for a received IST.
     */
    public function receipt($id)
    {
        try {
            $receivedId = $this->statusId('Received');

            $ist = Transfer::with(array_merge(self::WITH_RELATIONS, [
                'transferTracking.recordedBy',
                'receivedBy',
            ]))->whereNotNull('transfer_request_id')
               ->where('status_id', $receivedId)
               ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            return $this->success($ist, 'IST receipt data retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found or not yet received', 404);
        }
    }

    /**
     * Stream a PDF receipt for a received IST.
     */
    public function receiptPdf($id)
    {
        try {
            $receivedId = $this->statusId('Received');

            $ist = Transfer::with(array_merge(self::WITH_RELATIONS, ['receivedBy']))
                ->whereNotNull('transfer_request_id')
                ->where('status_id', $receivedId)
                ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('ist.receipt', ['ist' => $ist])
                ->setPaper('a4', 'portrait');

            return $pdf->download('IST-' . $ist->transfer_number . '.pdf');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], 500);
        }
    }
}
