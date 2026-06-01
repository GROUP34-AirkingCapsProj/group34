<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TblTransferTracking;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferRequestController extends Controller
{
    use ApiResponse;

    private const WITH_RELATIONS = [
        'fromLocation',
        'toLocation',
        'requestedBy',
        'approvedBy',
        'status',
        'details.product',
    ];

    private function statusId(string $name): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');
        return $id !== null ? (int) $id : null;
    }

    public function index(Request $request)
    {
        try {
            $user   = $request->user();
            $roleId = $user->role?->role_id ?? 0;
            $isAdmin = in_array($user->role?->role_name, ['admin', 'inventory_analyst'], true) || $roleId === 1;

            // Only request records (transfer_request_id IS NULL)
            $query = Transfer::with(self::WITH_RELATIONS)
                ->whereNull('transfer_request_id');

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
                'Transfer requests retrieved'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve transfer requests: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_location_id'               => 'required|exists:locations,location_id',
                'to_location_id'                 => 'required|different:from_location_id|exists:locations,location_id',
                'transfer_date'                  => 'required|date',
                'notes'                          => 'nullable|string|max:500',
                'products'                       => 'required|array|min:1',
                'products.*.product_id'          => 'required|exists:products,product_id',
                'products.*.quantity_requested'  => 'required|integer|min:1',
            ]);

            $user = $request->user();

            DB::beginTransaction();

            $pendingId = $this->statusId('Pending');

            // Generate transfer number: TR-REQ-YYYYMMDD-XXXX
            $nextId = (int) DB::table('transfers')->max('transfer_id') + 1;
            $transferNumber = 'TR-REQ-' . date('Ymd') . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

            $transfer = Transfer::create([
                'transfer_request_id'       => null,
                'from_location_id'          => $validated['from_location_id'],
                'to_location_id'            => $validated['to_location_id'],
                'transfer_number'           => $transferNumber,
                'transfer_date'             => $validated['transfer_date'],
                'requested_by'              => $user->user_id,
                'status_id'                 => $pendingId,
                'notes'                     => $validated['notes'] ?? null,
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);

            foreach ($validated['products'] as $row) {
                $transfer->details()->create([
                    'product_id'          => $row['product_id'],
                    'quantity_requested'  => (int) $row['quantity_requested'],
                    'quantity_transferred' => 0,
                    'quantity_scanned'    => 0,
                    'quantity_received'   => 0,
                ]);
            }

            TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $validated['to_location_id'],
                'status_note' => 'Transfer request submitted',
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            DB::commit();

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $user->user_id,
                    'action'     => 'transfer_request_submitted',
                    'table_name' => 'transfers',
                    'record_id'  => $transfer->transfer_id,
                    'old_values' => null,
                    'new_values' => [
                        'transfer_number'    => $transfer->transfer_number,
                        'from_location_id'   => $validated['from_location_id'],
                        'to_location_id'     => $validated['to_location_id'],
                        'products_requested' => count($validated['products']),
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $this->success(
                $transfer->load(self::WITH_RELATIONS),
                'Transfer request submitted successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to submit transfer request: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $transfer = Transfer::with(array_merge(self::WITH_RELATIONS, ['transferTracking.recordedBy']))
                ->whereNull('transfer_request_id')
                ->findOrFail($id);

            // Attach current stock at source for each line item
            $fromLocId = $transfer->from_location_id;
            foreach ($transfer->details as $detail) {
                $detail->stock_at_source = (int) Inventory::where('location_id', $fromLocId)
                    ->where('product_id', $detail->product_id)
                    ->value('quantity_on_hand') ?? 0;
            }

            return $this->success($transfer, 'Transfer request retrieved');
        } catch (\Exception $e) {
            return $this->error('Transfer request not found', 404);
        }
    }

    public function approve(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'quantities'                              => 'sometimes|array',
                'quantities.*.transfer_detail_id'         => 'required|integer',
                'quantities.*.quantity_approved'          => 'required|integer|min:1',
            ]);

            $transfer = Transfer::with('details')->whereNull('transfer_request_id')->findOrFail($id);

            $pendingId  = $this->statusId('Pending');
            $approvedId = $this->statusId('Approved');

            if ((int) $transfer->status_id !== $pendingId) {
                return $this->error('Only pending requests can be approved.', 422);
            }

            DB::beginTransaction();

            // Apply per-line quantity overrides
            foreach ($validated['quantities'] ?? [] as $override) {
                TransferDetail::where('transfer_detail_id', $override['transfer_detail_id'])
                    ->where('transfer_id', $transfer->transfer_id)
                    ->update(['quantity_requested' => (int) $override['quantity_approved']]);
            }

            $user = $request->user();
            $username = $user->username ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: "user#{$user->user_id}";

            $transfer->approved_by = $user->user_id;
            $transfer->status_id   = $approvedId;
            $transfer->save();

            TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $transfer->to_location_id,
                'status_note' => "Transfer request approved by {$username}",
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            DB::commit();

            return $this->success(
                $transfer->fresh()->load(self::WITH_RELATIONS),
                'Transfer request approved'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to approve transfer request: ' . $e->getMessage(), 500);
        }
    }

    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'nullable|string|max:500',
            ]);

            $transfer = Transfer::whereNull('transfer_request_id')->findOrFail($id);

            $pendingId  = $this->statusId('Pending');
            $rejectedId = $this->statusId('Rejected');

            if ((int) $transfer->status_id !== $pendingId) {
                return $this->error('Only pending requests can be rejected.', 422);
            }

            DB::beginTransaction();

            $transfer->status_id = $rejectedId;
            if (!empty($validated['rejection_reason'])) {
                $existing = $transfer->notes ?? '';
                $transfer->notes = trim("REJECTED: {$validated['rejection_reason']}\n\n{$existing}");
            }
            $transfer->save();

            TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $transfer->to_location_id,
                'status_note' => 'Transfer request rejected' . (!empty($validated['rejection_reason']) ? ': ' . $validated['rejection_reason'] : ''),
                'recorded_by' => $request->user()->user_id,
                'recorded_at' => now(),
            ]);

            DB::commit();

            return $this->success(
                $transfer->fresh()->load(self::WITH_RELATIONS),
                'Transfer request rejected'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to reject transfer request: ' . $e->getMessage(), 500);
        }
    }
}
