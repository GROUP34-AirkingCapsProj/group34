<?php

namespace App\Services;

use App\Models\ItemSerial;

/**
 * Which identity a tag carries, and who may reprint it.
 *
 * Both questions live here rather than in a controller because two callers
 * ask them — the printing queue, to badge and gate each card, and the print
 * endpoint, to decide what actually reaches the Zebra. Two copies of this
 * rule would drift, and the drift would show up as a label on the wrong box.
 *
 * NOTHING HERE MINTS ANYTHING. A supplier-coded carton keeps exactly one
 * identifier — the supplier's. See MaterialTagService::mintForScan for why.
 */
class TagPrintPolicy
{
    public const TIER_MINTED       = 'minted';
    public const TIER_SUPPLIER     = 'supplier';
    public const TIER_MANUFACTURER = 'manufacturer';

    /**
     * Order matters. A serialized appliance's row carries a NULL
     * supplier_barcode — ReceivingPostingService::mintTags never sets one —
     * so testing the barcode first would classify a manufacturer's serial as
     * one of our own tags, and bulk printing would then run off duplicates of
     * a serial we did not issue and cannot reissue.
     *
     * Caller must eager-load product.applianceDetail and
     * product.installationMaterialDetail before iterating a collection.
     * Product::isSerialized() lazy-loads whichever detail it needs, and a
     * queue page of up to a hundred rows would otherwise cost a couple of
     * hundred extra queries — one detail lookup per row instead of one
     * eager-loaded batch.
     *
     * Assumes $serial->product is not null. printable() checks that before
     * ever reaching tier(); a caller that calls tier() directly on a
     * product-less row will get TIER_MINTED, the most permissive tier. The
     * product_id foreign key makes that row unreachable today, so this is a
     * documented assumption, not a guard.
     */
    public function tier(ItemSerial $serial): string
    {
        if ($serial->product?->isSerialized()) {
            return self::TIER_MANUFACTURER;
        }

        if ($serial->supplier_barcode !== null && $serial->supplier_barcode !== '') {
            return self::TIER_SUPPLIER;
        }

        return self::TIER_MINTED;
    }

    /**
     * @param  string  $mode  'bulk' — a selection sent by the roll — or
     *                        'single', one deliberate reprint of one label.
     */
    public function printable(ItemSerial $serial, string $mode): bool
    {
        if ($serial->product === null) {
            return false;
        }

        /* True in every mode: the tag names stock that has left. */
        if ($serial->serial_type === 'issued') {
            return false;
        }

        if ($mode === 'single') {
            return true;
        }

        /* Bulk keeps the original rule verbatim: only what we minted. A bulk
           run that included supplier codes would put a second sticker on
           every already-labelled carton in a delivery. */
        return $this->tier($serial) === self::TIER_MINTED;
    }
}
