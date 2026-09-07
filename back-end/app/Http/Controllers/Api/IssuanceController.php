<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\RealtimeBroadcaster;
use App\Support\IssuanceStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use App\Services\AirconLineGrouper;
use App\Services\InventoryBatchService;

class IssuanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AirconLineGrouper $airconLineGrouper)
    {
    }

    public function index(Request $request)
    {
        try {
            $query = Issuance::query()
                ->forUser($request->user())
                ->with(['location', 'issuedToUser', 'issuedByUser', 'approvedBy', 'status', 'details.product']);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('issuance_type')) {
                $query->where('issuance_type', $request->issuance_type);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->filled('search')) {
                // NB: there is no `issuance_number` column — dr_number is the
                // document number. Grouped so the OR does not escape the other
                // filters' AND semantics.
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('dr_number', 'like', $term)
                      ->orWhere('external_reference', 'like', $term);
                });
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Issuances retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve issuances: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_id'          => 'required|exists:locations,location_id',
                'issuance_date'        => 'required|date',
                'issuance_type'        => 'required|string|max:50',
                'external_reference'   => 'nullable|string|max:100',
                'purpose'              => 'nullable|string',
                'issued_to_user_id'    => 'nullable|exists:users,user_id',
                'expected_return_date' => 'nullable|date',
                'actual_return_date'   => 'nullable|date',
                'issued_by'            => 'nullable|exists:users,user_id',
                'approved_by'          => 'nullable|exists:users,user_id',
                'status_id'            => 'nullable|exists:status_lookup,status_id',
                'details'              => 'required|array|min:1',
                'details.*.product_id'        => 'required|exists:products,product_id',
                'details.*.quantity_issued'   => 'required|integer|min:1',
                'details.*.quantity_returned' => 'nullable|integer|min:0',
                'details.*.condition_issued'  => 'nullable|string|max:50',
                'details.*.condition_returned'=> 'nullable|string|max:50',
            ]);

            // No location guard here on purpose: EnsureAssignedLocationAccess
            // ('location.scope', applied to every non-GET route in this group)
            // has already overwritten location_id with the user's own for any
            // location-strict role, so by this point the value cannot name
            // another branch. A check here would be unreachable.
            DB::beginTransaction();

            $issuance = Issuance::create(Arr::except($validated, ['details']));

            // Preloaded outside the loop: packaging comes off the product and
            // resolving it per iteration would be an N+1 across the detail lines.
            $issuedProducts = Product::with(['installationMaterialDetail', 'applianceDetail'])
                ->whereIn('product_id', collect($validated['details'])->pluck('product_id')->all())
                ->get()
                ->keyBy('product_id');

            foreach ($validated['details'] as $detail) {
                $issuance->details()->create($detail);

                // Deduct inventory at issuance location.
                //
                // Locked before it is read, not just before it is written: the
                // availability check below and the deduction that follows have
                // to be one atomic step. Unlocked, two requests for the last 5
                // pieces both read 5, both pass the check, and both deduct —
                // ten issued against five, and because the write is
                // max(0, ...) the row settles on 0 instead of going negative,
                // so nothing surfaces it. The same lock is what
                // InventoryBatchService's class contract requires of anyone
                // calling deductFifo() below; its own lockForUpdate() calls
                // rely on this outer lock to serialize. IssuanceScanController
                // does this correctly and this path did not.
                $qty = (int) ($detail['quantity_issued'] ?? 0);
                $inventory = Inventory::where('location_id', $validated['location_id'])
                    ->where('product_id', $detail['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw new \RuntimeException('Insufficient stock: inventory record not found for selected location/product.');
                }

                $available = (int) ($inventory->available_quantity ?? $inventory->quantity_on_hand ?? 0);
                if ($available < $qty) {
                    throw new \RuntimeException("Insufficient stock for product_id {$detail['product_id']}.");
                }

                $issuedProduct = $issuedProducts->get($detail['product_id']);
                $issuedPpp = ($issuedProduct && ! $issuedProduct->isSerialized())
                    ? $issuedProduct->getPiecesPerPackage()
                    : 0;
                $inventory->setPieces((int) $inventory->quantity_on_hand - $qty, $issuedPpp);
                $inventory->save();

                $touched = app(InventoryBatchService::class)->deductFifo(
                    (int) $detail['product_id'],
                    (int) $validated['location_id'],
                    $qty
                );
                foreach ($touched as $batchId => $deductedQuantity) {
                    if ($deductedQuantity <= 0) continue;
                    \App\Models\InventoryBatchMovement::create([
                        'batch_id' => $batchId,
                        'movement_type' => 'stock_out',
                        'issuance_id' => $issuance->issuance_id,
                        'product_id' => $detail['product_id'],
                        'from_location_id' => $validated['location_id'],
                        'quantity' => $deductedQuantity,
                        'performed_by' => $issuance->issued_by,
                        'moved_at' => now(),
                    ]);
                }
            }

            DB::commit();

            RealtimeBroadcaster::inventoryChanged((int) $issuance->location_id, 'stock_issued');

            return $this->success($issuance->load(['location', 'issuedToUser', 'details.product']), 'Issuance created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create issuance: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $issuance = Issuance::with(['location', 'issuedToUser', 'issuedByUser', 'approvedBy', 'status', 'details.product'])->findOrFail($id);

            // array_merge over toArray() rather than setting an attribute on
            // the model: response()->json() on a model serialises through the
            // same toArray(), so every key the frontend already reads comes out
            // byte for byte, and aircon_groups is a pure sibling addition.
            return $this->success(
                array_merge($issuance->toArray(), [
                    'aircon_groups' => $this->airconGroups($issuance->details),
                ]),
                'Issuance retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Issuance not found', 404);
        }
    }

    /**
     * Aircon groups for this issuance's lines, or [] when no line is tagged.
     *
     * The early return is the common path, not an optimisation for a rare one:
     * every issuance written before aircon_model_id existed is untagged, and
     * those must not start paying for the grouper's three queries. The grouper
     * is called ONCE with every line — calling it per line would make a detail
     * view N+1 in the document's line count.
     *
     * Extra keys in a line array pass through the grouper untouched into the
     * group's `lines`, so issuance_detail_id rides along and the UI can match a
     * group back to the rows it already rendered.
     *
     * @param  \Illuminate\Support\Collection<int,IssuanceDetail>  $details
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
                'issuance_detail_id' => (int) $detail->issuance_detail_id,
                'product_id'         => (int) $detail->product_id,
                'quantity'           => (int) $detail->quantity_issued,
                'aircon_model_id'    => $modelId,
            ];
        }

        return $tagged ? $this->airconLineGrouper->group($lines) : [];
    }

    /**
     * Whether this user may act on this particular issuance.
     *
     * EnsureAssignedLocationAccess pins a location-strict user's location_id on
     * the way in, which is enough for create — the row is made at their own
     * branch either way. It does nothing for an action addressed by id, where
     * the record already exists and may belong to anyone, so the record's own
     * location is what has to be checked.
     */
    private function mayActOn(Request $request, Issuance $issuance): bool
    {
        $user = $request->user();

        return $user->isCentralized()
            || (int) $user->scopedLocationId() === (int) $issuance->location_id;
    }

    public function cancel(Request $request, $id)
    {
        try {
            return DB::transaction(function () use ($request, $id) {
                $issuance = Issuance::lockForUpdate()->findOrFail($id);

                if (! $this->mayActOn($request, $issuance)) {
                    return $this->forbidden('You do not have access to this location.');
                }

                if ((int) $issuance->status_id !== IssuanceStatus::draft()) {
                    return $this->error('Only a draft issue-out can be cancelled.', 422);
                }

                $issuance->update(['status_id' => IssuanceStatus::cancelled()]);

                return $this->success($issuance->fresh(['status']), 'Issue-out cancelled.');
            });
        } catch (ModelNotFoundException $e) {
            return $this->error('Issuance not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to cancel issue-out: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $issuance = Issuance::findOrFail($id);

            $validated = $request->validate([
                'location_id'          => 'sometimes|exists:locations,location_id',
                'issuance_date'        => 'sometimes|date',
                'issuance_type'        => 'sometimes|string|max:50',
                'external_reference'   => 'nullable|string|max:100',
                'purpose'              => 'nullable|string',
                'issued_to_user_id'    => 'nullable|exists:users,user_id',
                'expected_return_date' => 'nullable|date',
                'actual_return_date'   => 'nullable|date',
                'issued_by'            => 'nullable|exists:users,user_id',
                'approved_by'          => 'nullable|exists:users,user_id',
                'status_id'            => 'nullable|exists:status_lookup,status_id',
            ]);

            // The id comes from the URL, and location.scope only rewrites
            // request INPUT — it never looks at the row being addressed. So
            // nothing else stops a user at one branch editing another branch's
            // issuance; that check has to happen here, against the record.
            if (! $this->mayActOn($request, $issuance)) {
                return $this->forbidden('You do not have access to this location.');
            }

            $issuance->update($validated);

            return $this->success($issuance->load(['location', 'issuedToUser', 'details.product']), 'Issuance updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update issuance: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $issuance = Issuance::findOrFail($id);
            $issuance->delete();
            return $this->success(null, 'Issuance archived successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete issuance: ' . $e->getMessage(), 500);
        }
    }

    public function archived(Request $request)
    {
        $query = Issuance::onlyTrashed()->with(['location', 'customer', 'status']);

        if ($request->filled('search')) {
            $query->where('dr_number', 'like', '%' . $request->search . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived issuances retrieved'
        );
    }

    public function restore($id)
    {
        $issuance = Issuance::onlyTrashed()->find($id);
        if (! $issuance) {
            return $this->notFound('Archived issuance not found');
        }
        $issuance->restore();
        return $this->success($issuance->fresh()->load(['location', 'customer', 'status']), 'Issuance restored successfully');
    }

    public function forceDestroy($id)
    {
        $issuance = Issuance::onlyTrashed()->find($id);
        if (! $issuance) {
            return $this->notFound('Archived issuance not found');
        }
        $issuance->forceDelete();
        return $this->success(null, 'Issuance permanently deleted');
    }
}
