<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ItemSerial;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Attaches the digital tags minted for a receipt onto the stock-in rows that
 * receipt produced, so a counted line — which has no supplier barcode — still
 * shows the identifiers that were actually recorded for it.
 *
 * WHAT THE DATA CAN AND CANNOT SAY
 * --------------------------------
 * item_serial carries receiving_id and batch_id, never receiving_detail_id,
 * and ReceivingPostingService accumulates one batch per receiving + product +
 * location — so neither column can single out one receiving_details row when a
 * receipt holds several lines for the same product.
 *
 * The one further discriminator the data does hold is supplier_barcode:
 * MaterialTagService::mintForScan stores the scanned barcode on the tag, while
 * mintForCount leaves it null. Tags are therefore attributed on
 * (receiving_id, product_id, supplier_barcode) — which is exact whenever that
 * triple selects a single stock-in line, and is explicitly reported as
 * 'shared' when it does not.
 *
 * Each row gains:
 *   generated_tags       string[]  serial numbers attributed to this row
 *   generated_tag_count  int
 *   tag_attribution      'exact' | 'shared'
 *   tag_line_count       int  how many stock-in lines share this tag set
 *
 * 'shared' means "these tags belong to this group of lines, and the data
 * cannot say which line each one came from". The UI must say so too rather
 * than repeating the list as if each line owned it.
 *
 * The field names predate the scanned-material change: a material that
 * arrives WITH a supplier barcode gets no `INT-` serial (see
 * MaterialTagService::mintForScan), so `generated_tags` on a scanned line's
 * row holds that supplier code, not anything this system minted. Nothing
 * downstream should describe it as "generated" — a UI label doing so would
 * be actively misleading, since nothing was.
 */
trait AttachesGeneratedTags
{
    /**
     * @param  Collection|iterable  $rows  stock-in rows carrying receiving_id,
     *                                     product_id and scanned_barcode.
     */
    protected function attachGeneratedTags($rows): void
    {
        $rows = $rows instanceof Collection ? $rows : collect($rows);

        if ($rows->isEmpty()) {
            return;
        }

        $receivingIds = $rows->pluck('receiving_id')->filter()->unique()->values()->all();
        $productIds   = $rows->pluck('product_id')->filter()->unique()->values()->all();

        // Only unserialized products get generated tags. ReceivingPostingService
        // stores an appliance's own scanned serial verbatim in item_serial, and
        // that serial is the manufacturer's — presenting it as something this
        // system minted and can reprint would be a lie. Asking the model rather
        // than matching an 'INT-' prefix keeps this in step with the rule the
        // receiving path actually branches on.
        $taggedProductIds = empty($productIds) ? [] : Product::with(['applianceDetail', 'installationMaterialDetail'])
            ->whereIn('product_id', $productIds)
            ->get()
            ->reject(fn (Product $p) => $p->isSerialized())
            ->pluck('product_id')
            ->all();

        $tagsByKey = collect();
        if (! empty($receivingIds) && ! empty($taggedProductIds)) {
            $tagsByKey = ItemSerial::query()
                ->whereIn('receiving_id', $receivingIds)
                ->whereIn('product_id', $taggedProductIds)
                ->orderBy('serial_id')
                ->get(['receiving_id', 'product_id', 'supplier_barcode', 'serial_number'])
                ->groupBy(fn ($tag) => $this->generatedTagKey(
                    $tag->receiving_id,
                    $tag->product_id,
                    $tag->supplier_barcode
                ))
                ->map(fn ($group) => $group->pluck('serial_number')->values()->all());
        }

        // How many stock-in lines resolve to the same tag key: 1 means the
        // attribution is exact, more means the tags belong to the group.
        $lineCounts = $rows
            ->groupBy(fn ($row) => $this->generatedTagKey(
                $row->receiving_id ?? null,
                $row->product_id ?? null,
                $row->scanned_barcode ?? null
            ))
            ->map(fn ($group) => $group->count());

        foreach ($rows as $row) {
            $key   = $this->generatedTagKey($row->receiving_id ?? null, $row->product_id ?? null, $row->scanned_barcode ?? null);
            $tags  = $tagsByKey->get($key, []);
            $lines = (int) ($lineCounts[$key] ?? 1);

            $row->generated_tags      = $tags;
            $row->generated_tag_count = count($tags);
            $row->tag_line_count      = $lines;
            $row->tag_attribution     = $lines > 1 ? 'shared' : 'exact';
        }
    }

    private function generatedTagKey($receivingId, $productId, $supplierBarcode): string
    {
        // A null barcode (counted) and the literal string 'null' must not
        // collide, hence the explicit sentinel rather than a bare cast.
        $barcode = ($supplierBarcode === null || $supplierBarcode === '')
            ? "\0counted"
            : (string) $supplierBarcode;

        return $receivingId . '|' . $productId . '|' . $barcode;
    }
}
