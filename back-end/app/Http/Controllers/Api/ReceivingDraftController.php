<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Services\ReceivingDraftStagingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD for a delivery being staged before it is posted.
 *
 * Nothing in this controller writes to inventory, inventory_batches,
 * receiving_details or item_serial. That is a structural guarantee, not a
 * convention: a bug here can lose a clerk's typing, but it cannot corrupt
 * stock. Posting lives in ReceivingDraftSubmitController alone.
 */
class ReceivingDraftController extends Controller
{
    use ApiResponse, \App\Traits\NormalizesBarcode;

    public function __construct(private readonly ReceivingDraftStagingService $staging)
    {
    }

    /** The open draft for this PO+warehouse, or null when there is none. */
    public function show(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'po_id'       => 'required|integer|exists:purchase_orders,po_id',
            'location_id' => 'required|integer|exists:locations,location_id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $draft = ReceivingDraft::openFor((int) $request->po_id, (int) $request->location_id);

        return $this->success(
            $draft ? $this->present($draft) : null,
            $draft ? 'Open delivery found' : 'No open delivery'
        );
    }

    /**
     * Open a draft, or resume the one already open.
     *
     * Resuming rather than creating is deliberate. Two clerks working the same
     * truck must land on one list; a second draft would let the same delivery
     * be staged twice and posted twice.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'po_id'       => 'required|integer|exists:purchase_orders,po_id',
            'location_id' => 'required|integer|exists:locations,location_id',
            'zone_label'  => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $po = PurchaseOrder::find($request->po_id);

        if ($po->verification_status !== 'verified') {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'This purchase order must be verified by an auditor before receiving.',
            ], 'Rejected');
        }

        // 8 = Approved, 10 = Authorized, 12 = Partially Received.
        if (! in_array((int) $po->status_id, [8, 10, 12], true)) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'PO must be Approved, Authorized, or Partially Received.',
            ], 'Rejected');
        }

        $draft = ReceivingDraft::openFor((int) $request->po_id, (int) $request->location_id);

        if (! $draft) {
            $draft = ReceivingDraft::create([
                'po_id'       => (int) $request->po_id,
                'location_id' => (int) $request->location_id,
                'opened_by'   => $request->user()?->user_id,
                'status'      => 'open',
                'zone_label'  => $request->input('zone_label'),
            ]);
        } elseif ($request->filled('zone_label')) {
            $draft->update(['zone_label' => $request->input('zone_label')]);
        }

        return $this->success($this->present($draft->fresh()), 'Delivery ready');
    }

    /**
     * Stage one scan or one hand count.
     *
     * The piece maths mirrors the two live posting endpoints exactly, so a
     * staged row posts to the same numbers a direct scan or count would have:
     *   - a scan is one package (or one appliance unit), never more;
     *   - a count is the clerk's units, converted by pieces_per_package, with
     *     actual_pieces overriding the expectation when a box came up short.
     */
    public function storeItem(Request $request, ReceivingDraft $draft)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        $validator = Validator::make($request->all(), [
            'po_detail_id'  => 'required|integer|exists:purchase_order_details,po_detail_id',
            'entry_mode'    => 'required|string|in:scanned,counted',
            'barcode'       => 'nullable|string|max:191',
            'quantity'      => 'nullable|integer|min:1',
            'actual_pieces' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $poDetail = PurchaseOrderDetail::with(['product.applianceDetail', 'product.installationMaterialDetail'])
            ->find($request->po_detail_id);

        /** @var ?Product $product */
        $product = $poDetail?->product;

        if (! $product) {
            return $this->error('Line item has no product.', 422);
        }

        $barcode = $request->filled('barcode')
            ? ($this->canonicalSerial((string) $request->input('barcode')) ?: null)
            : null;

        $entryMode = $request->input('entry_mode');

        if ($entryMode === 'counted' && $product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items must be scanned individually.',
            ], 'Rejected');
        }

        [$pieces, $packages, $expected, $units] = $entryMode === 'scanned'
            ? $this->scanQuantities($product, (int) $poDetail->pieces_per_order_unit, $request->filled('quantity') ? (int) $request->input('quantity') : null)
            : $this->countQuantities($product, (int) $poDetail->pieces_per_order_unit, (int) $request->input('quantity', 1), $request->input('actual_pieces'));

        $check = $this->staging->check($draft, $poDetail, $product, $pieces, $packages, $barcode);

        if ($check['action'] === 'rejected') {
            return $this->success([
                'action' => 'rejected',
                'reason' => $check['reason'],
            ], 'Rejected');
        }

        $item = ReceivingDraftItem::create([
            'draft_id'          => $draft->draft_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail->po_detail_id,
            'entry_mode'        => $entryMode,
            'scanned_barcode'   => $barcode,
            'quantity_amount'   => $pieces,
            'packages_received' => $packages,
            'pieces_expected'   => $expected,
            // Counting IS the verification: the clerk typed the number of
            // pieces they physically had in front of them. A scan proves a
            // carton arrived and says nothing about what was inside it.
            'contents_verified' => $entryMode === 'counted',
            'units_entered'     => $units,
        ]);

        return $this->success([
            'action'  => 'staged',
            'item'    => $this->presentItem($item->fresh('product')),
            'warning' => $check['warning'] ?? null,
            'draft'   => $this->present($draft->fresh()),
        ], 'Staged');
    }

    /**
     * Correct a staged row's quantity.
     *
     * `pieces_expected` is never rewritten here. It records what the packages
     * should have held, and the gap between it and quantity_amount is the
     * shortfall — rewriting it would erase the discrepancy the clerk is
     * reporting.
     */
    public function updateItem(Request $request, ReceivingDraft $draft, ReceivingDraftItem $item)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        if ((int) $item->draft_id !== (int) $draft->draft_id) {
            return $this->error('That row does not belong to this delivery.', 404);
        }

        $validator = Validator::make($request->all(), [
            'actual_pieces' => 'nullable|integer|min:0',
            'packages'      => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        if (! $request->has('actual_pieces') && ! $request->has('packages')) {
            return $this->error('Nothing to change.', 422);
        }

        $changes = [];

        if ($request->has('packages') && $item->packages_received !== null) {
            $packages = (int) $request->input('packages');
            $ppp      = max(1, (int) PurchaseOrderDetail::whereKey($item->po_detail_id)->value('pieces_per_order_unit'));

            $changes['packages_received'] = $packages;
            $changes['units_entered']     = $packages;
            $changes['pieces_expected']   = $packages * $ppp;
            // Re-deriving pieces from the new package count keeps the row
            // coherent; an explicit actual_pieces in the same request wins
            // below, which is what a clerk correcting both at once means.
            $changes['quantity_amount']   = $packages * $ppp;
        }

        if ($request->has('actual_pieces')) {
            $changes['quantity_amount'] = (int) $request->input('actual_pieces');
            // Editing the piece count IS opening the box and counting.
            $changes['contents_verified'] = true;
        }

        $item->update($changes);

        return $this->success([
            'action' => 'updated',
            'item'   => $this->presentItem($item->fresh('product')),
            'draft'  => $this->present($draft->fresh()),
        ], 'Updated');
    }

    /** Remove a wrong row. This is the fix for a mis-selected line. */
    public function destroyItem(ReceivingDraft $draft, ReceivingDraftItem $item)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        if ((int) $item->draft_id !== (int) $draft->draft_id) {
            return $this->error('That row does not belong to this delivery.', 404);
        }

        $item->delete();

        return $this->success([
            'action' => 'removed',
            'draft'  => $this->present($draft->fresh()),
        ], 'Removed');
    }

    /**
     * Abandon the whole delivery.
     *
     * The items are deleted so their barcodes become free again — the goods
     * still have to be received somehow, and a discarded draft must not hold
     * a label hostage. The header is kept, marked `discarded`, so the record
     * that someone started and abandoned a delivery survives.
     */
    public function destroy(ReceivingDraft $draft)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        $draft->items()->delete();
        $draft->update(['status' => 'discarded', 'discarded_at' => now()]);

        return $this->success(['action' => 'discarded'], 'Delivery discarded');
    }

    /**
     * Material scans may record the remaining document quantity in pieces.
     * Appliances always record one serial; omitted quantities retain one-package behavior.
     *
     * @return array{0: int, 1: ?int, 2: ?int, 3: int} pieces, packages, expected, units
     */
    private function scanQuantities(Product $product, int $snapshotSize, ?int $pieces = null): array
    {
        if ($product->isSerialized()) {
            return [1, null, null, 1];
        }

        $ppp = max(1, $snapshotSize);

        if ($pieces !== null) {
            $packages = $pieces % $ppp === 0 ? intdiv($pieces, $ppp) : null;
            return [$pieces, $packages, $packages !== null ? $pieces : null, $packages ?? $pieces];
        }

        return [$ppp, 1, $ppp, 1];
    }

    /**
     * @return array{0: int, 1: ?int, 2: ?int, 3: int} pieces, packages, expected, units
     */
    private function countQuantities(Product $product, int $snapshotSize, int $units, $actualPieces): array
    {
        $countUnit = $product->installationMaterialDetail?->count_unit ?? 'package';

        if ($countUnit === 'piece') {
            // The clerk counted pieces directly — expected and actual are the
            // same thing, so there is nothing to verify and nothing to record.
            return [$units, null, null, $units];
        }

        // The expectation comes from the item master, never from the clerk.
        $expected = $units * max(1, $snapshotSize);
        $pieces   = $actualPieces !== null ? (int) $actualPieces : $expected;

        return [$pieces, $units, $expected, $units];
    }

    private function present(ReceivingDraft $draft): array
    {
        $items = $draft->items()->with('product')->orderBy('draft_item_id')->get();

        return [
            'draft_id'      => (int) $draft->draft_id,
            'po_id'         => (int) $draft->po_id,
            'location_id'   => (int) $draft->location_id,
            'status'        => $draft->status,
            'zone_label'    => $draft->zone_label,
            'staged_pieces' => (int) $items->sum('quantity_amount'),
            'staged_count'  => $items->count(),
            'items'         => $items->map(fn ($i) => $this->presentItem($i))->values()->all(),
        ];
    }

    private function presentItem(ReceivingDraftItem $item): array
    {
        return [
            'draft_item_id'     => (int) $item->draft_item_id,
            'product_id'        => (int) $item->product_id,
            'product_name'      => $item->product?->product_name,
            'po_detail_id'      => $item->po_detail_id ? (int) $item->po_detail_id : null,
            'entry_mode'        => $item->entry_mode,
            'scanned_barcode'   => $item->scanned_barcode,
            'quantity_amount'   => (int) $item->quantity_amount,
            'packages_received' => $item->packages_received === null ? null : (int) $item->packages_received,
            'pieces_expected'   => $item->pieces_expected === null ? null : (int) $item->pieces_expected,
            'contents_verified' => (bool) $item->contents_verified,
            'units_entered'     => (int) $item->units_entered,
            'staged_at'         => $item->created_at?->toIso8601String(),
        ];
    }
}
