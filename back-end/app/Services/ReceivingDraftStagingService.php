<?php

namespace App\Services;

use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;

/**
 * Decides whether a row may be staged into a draft.
 *
 * This service exists to give the clerk instant feedback, NOT to protect the
 * data — every rule enforced here is enforced again, authoritatively, at submit.
 * That separation is deliberate: it means this class never needs to touch
 * inventory, batches, receiving_details or item_serial, and so a bug in it
 * cannot corrupt stock. If you find yourself wanting to write from here, the
 * logic belongs in ReceivingDraftSubmitService instead.
 */
class ReceivingDraftStagingService
{
    public function __construct(private ReceivingPostingService $posting)
    {
    }

    /**
     * @param  ?int  $packages  Packages this row claims, or null for an
     *         appliance or a piece-counted line, which have no package concept.
     * @return array{action: 'allowed'|'rejected', reason?: string, warning?: ?array}
     *         'rejected' means the row must not be staged. 'allowed' may still
     *         carry a `warning` the clerk should see but must be free to ignore.
     */
    public function check(
        ReceivingDraft $draft,
        PurchaseOrderDetail $poDetail,
        Product $product,
        int $pieces,
        ?int $packages,
        ?string $barcode
    ): array {
        // The line must belong to the PO this draft is receiving. Without this
        // a mis-wired client could stage another order's goods into this
        // delivery and they would post on submit as if they had been ordered.
        if ((int) $poDetail->po_id !== (int) $draft->po_id) {
            return [
                'action' => 'rejected',
                'reason' => 'That item is not on this purchase order.',
            ];
        }

        if ((int) $poDetail->product_id !== (int) $product->product_id) {
            return [
                'action' => 'rejected',
                'reason' => 'That product does not match the selected line.',
            ];
        }

        // An appliance must never enter stock without a serial. post() throws
        // on this as its last line of defence; catching it here turns a 500
        // into a clerk-readable message before anything is staged.
        if ($product->isSerialized() && ! $barcode) {
            return [
                'action' => 'rejected',
                'reason' => 'Appliances must be scanned individually.',
            ];
        }

        if ($barcode) {
            $duplicate = $this->findDuplicate($barcode);
            if ($duplicate !== null) {
                return ['action' => 'rejected', 'reason' => $duplicate];
            }
        }

        // Overage WARNS, it does not block. The clerk must be able to stage
        // what is physically on the floor and then decide; refusing the row
        // here would leave them with a box they cannot describe.
        //
        // The warning accounts for what is already staged in this draft as well
        // as what is already posted, because checkOverage only knows about
        // posted rows and the draft has not posted anything yet.
        $stagedPieces   = $draft->stagedPiecesForLine((int) $poDetail->po_detail_id);
        $stagedPackages = $draft->stagedPackagesForLine((int) $poDetail->po_detail_id);

        $warning = $this->posting->checkOverage(
            $poDetail,
            $product,
            $pieces + $stagedPieces,
            $packages === null ? null : $packages + $stagedPackages
        );

        return ['action' => 'allowed', 'warning' => $warning];
    }

    /**
     * A human-readable reason this barcode cannot be used again, or null.
     *
     * Every barcode is a one-time-use identifier. Two places can already hold
     * it: a posted receipt (item_serial) or another clerk's open draft.
     */
    private function findDuplicate(string $barcode): ?string
    {
        $normalized = trim($barcode);

        if (ItemSerial::where('serial_number', $normalized)->exists()) {
            return "Barcode \"{$normalized}\" has already been recorded as received. "
                . 'Each barcode is a one-time-use identifier — check the label.';
        }

        $stagedElsewhere = ReceivingDraftItem::where('scanned_barcode', $normalized)
            ->whereHas('draft', fn ($q) => $q->where('status', 'open'))
            ->exists();

        if ($stagedElsewhere) {
            return "Barcode \"{$normalized}\" is already staged in an unsubmitted delivery. "
                . 'Each barcode is a one-time-use identifier — check the label.';
        }

        return null;
    }
}
