<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Mints digital tags (ItemSerial rows) for received installation materials.
 *
 * A tag identifies a physical thing (a package, a lot, or a scanned item) and
 * resolves to exactly one inventory batch. It carries no quantity of its own —
 * quantity lives in inventory_batches.quantity_remaining, which this service
 * never touches. Callers are responsible for adjusting stock levels (via
 * Inventory::setPieces()) separately.
 *
 * TRANSACTION CONTRACT: every method here must be invoked inside the
 * caller's own DB transaction. This service never opens or commits a
 * transaction itself, so that a rolled-back receipt leaves no orphan tags.
 *
 * UNIQUENESS: item_serial.serial_number carries a global UNIQUE constraint.
 * That constraint — not a PHP-side existence check — is the real guarantee
 * against collisions under concurrent receiving. This service computes a
 * candidate sequence number, attempts the insert, and on a unique-key
 * violation recounts and retries, up to a small cap. The violation is
 * detected via Laravel's UniqueConstraintViolationException so it works on
 * MySQL and PostgreSQL alike.
 */
class MaterialTagService
{
    /** Maximum attempts to find a free sequence number before giving up. */
    private const MAX_RETRIES = 25;

    /**
     * Mint tags for materials received by count. Granularity follows the
     * product's count_unit:
     *  - 'package' -> one tag per package (quantityInUnits tags minted).
     *  - 'piece'   -> one tag for the whole lot, regardless of quantity.
     *
     * @return ItemSerial[]
     */
    public function mintForCount(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        int $quantityInUnits,
        string $referenceLabel,
        ?int $userId = null
    ): array {
        $countUnit = $product->installationMaterialDetail?->count_unit ?? 'package';
        $tagCount  = $countUnit === 'piece' ? 1 : max(1, $quantityInUnits);

        $tags = [];
        for ($i = 0; $i < $tagCount; $i++) {
            $tags[] = $this->createTag($product, $batch, $locationId, null, $referenceLabel, $userId);
        }

        return $tags;
    }

    /**
     * A material that arrived WITH a supplier barcode keeps that code as its
     * identifier. No INT- serial is generated: a generated barcode exists only
     * because an item arrived without one, and minting a second identifier for
     * a labelled box is noise the warehouse has to reconcile.
     *
     * supplier_barcode is populated as well as serial_number, deliberately. It
     * makes `supplier_barcode IS NOT NULL` the honest test for "this identity
     * came from the supplier", instead of string-matching an INT- prefix whose
     * format we control and could change.
     */
    public function mintForScan(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        ?string $supplierBarcode,
        string $referenceLabel,
        ?int $userId = null
    ): ItemSerial {
        if (! $supplierBarcode) {
            // No code to adopt — fall back to a generated tag so the goods are
            // still identifiable. Reachable only if a caller bypasses the scan
            // endpoint's `required` barcode rule.
            //
            // This deliberately does NOT throw, unlike the serialized-appliance
            // guard in ReceivingPostingService::mintTags(). An appliance with no
            // serial is untraceable and must be refused outright. A material with
            // no barcode is the normal case this generated tag exists to serve —
            // materials are fungible by design, so a lot tag is a legitimate
            // identity for them, not a workaround.
            return $this->createTag($product, $batch, $locationId, null, $referenceLabel, $userId);
        }

        return ItemSerial::firstOrCreate(
            [
                'product_id'    => $product->product_id,
                'location_id'   => $locationId,
                'serial_number' => $supplierBarcode,
            ],
            [
                'batch_id'         => $batch->batch_id,
                'receiving_id'     => $batch->receiving_id,
                'supplier_barcode' => $supplierBarcode,
                'serial_type'      => 'received',
                'scanned_by'       => $userId,
                'status_id'        => ItemSerial::IN_STOCK_STATUS_ID,
            ]
        );
    }

    /**
     * Create a single ItemSerial tag row, retrying the sequence number on a
     * unique-key collision rather than pre-checking existence.
     */
    private function createTag(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        ?string $supplierBarcode,
        string $referenceLabel,
        ?int $userId
    ): ItemSerial {
        $attempts = 0;
        $offset   = 0;
        $prefix   = sprintf('INT-%s-%d-', $referenceLabel, $product->product_id);

        while (true) {
            $attempts++;
            $seq = $this->nextSequence($prefix) + $offset;
            $serialNumber = sprintf('%s%02d', $prefix, $seq);

            try {
                // Nested transaction => SAVEPOINT. The caller owns the outer
                // transaction (see the class contract), and on PostgreSQL a
                // failed statement aborts that whole transaction: every later
                // statement, including nextSequence()'s count on the next loop
                // pass, would fail with 25P02. Rolling back to the savepoint
                // keeps the retry loop workable. MySQL never needed it.
                return DB::transaction(fn () => ItemSerial::create([
                    'product_id'       => $product->product_id,
                    'location_id'      => $locationId,
                    'batch_id'         => $batch->batch_id,
                    'receiving_id'     => $batch->receiving_id,
                    'serial_number'    => $serialNumber,
                    'supplier_barcode' => $supplierBarcode,
                    'serial_type'      => 'received',
                    'scanned_by'       => $userId,
                    'status_id'        => ItemSerial::IN_STOCK_STATUS_ID,
                ]));
            } catch (QueryException $e) {
                // errorInfo[1] is the DRIVER's own error number: 1062 is MySQL's
                // duplicate-key code and has no meaning to PostgreSQL, which
                // reports a unique violation as SQLSTATE 23505. Testing the
                // MySQL number alone would make every collision look like a hard
                // failure on PostgreSQL and defeat the retry entirely.
                $isDuplicate = $e instanceof UniqueConstraintViolationException;

                if (!$isDuplicate || $attempts >= self::MAX_RETRIES) {
                    throw new RuntimeException(
                        "MaterialTagService: unable to mint a unique tag for product {$product->product_id} "
                        . "on batch {$batch->batch_id} after {$attempts} attempt(s).",
                        0,
                        $e
                    );
                }

                // Duplicate serial_number: another row already claimed this
                // sequence number (either a concurrent insert, or a gap-free
                // scan of the prefix landing on an existing serial). Bump the
                // local offset so the next candidate is strictly higher and
                // the loop is guaranteed to converge, then recount and retry.
                $offset++;
                continue;
            }
        }
    }

    /**
     * Next candidate sequence number for a given serial prefix: one more
     * than the number of tags already minted under that prefix. The prefix
     * (INT-{referenceLabel}-{product_id}-) is exactly the scope the unique
     * constraint on serial_number enforces, so counting must match it —
     * counting by batch_id instead would under-count whenever two batches
     * share a product_id and referenceLabel (e.g. one PO received into two
     * locations), producing colliding candidates that never resolve.
     *
     * This is still only a best-effort starting point — the actual
     * uniqueness guarantee is the DB constraint, enforced via retry with a
     * strictly-increasing offset in createTag().
     *
     * Uses POSITION(...) = 1 rather than a LIKE pattern: the prefix embeds the
     * caller-supplied referenceLabel (a PO number or receipt form), and
     * LIKE's '%'/'_' wildcards are not escaped by query bindings. POSITION
     * treats the prefix as a plain literal, so a reference label such as
     * 'PO_2401' cannot accidentally match unrelated rows like 'POX2401'.
     *
     * POSITION(x IN y) rather than MySQL's LOCATE(x, y): it is the SQL-standard
     * spelling and both MySQL and PostgreSQL implement it, where LOCATE does not
     * exist on PostgreSQL at all.
     */
    private function nextSequence(string $prefix): int
    {
        return ItemSerial::whereRaw('POSITION(? IN serial_number) = 1', [$prefix])->count() + 1;
    }
}
