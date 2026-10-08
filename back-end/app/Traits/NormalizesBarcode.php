<?php

namespace App\Traits;

use App\Models\ItemSerial;
use App\Models\Product;

trait NormalizesBarcode
{
    /**
     * Canonical value persisted for a manually typed or camera-decoded serial.
     * Camera decoders remove whitespace, so manual input must do the same.
     */
    protected function canonicalSerial(string $raw): string
    {
        return preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', trim($raw)) ?? trim($raw);
    }

    /**
     * Returns a deduplicated list of candidate strings to try for a given
     * raw barcode: original, upper, lower, no-whitespace, alphanumeric-only,
     * digits-only, and EAN-13 derived from a 12-digit UPC-A.
     */
    protected function barcodeLookupCandidates(string $raw): array
    {
        $s   = $this->canonicalSerial($raw);
        $set = [];

        $add = function (string $v) use (&$set) {
            $v = trim($v);
            if ($v !== '') {
                $set[$v] = true;
            }
        };

        $add($s);
        $add(strtoupper($s));
        $add(strtolower($s));
        $add(preg_replace('/\s+/', '', $s) ?? '');

        $alnum = preg_replace('/[^0-9A-Za-z]/', '', $s);
        if ($alnum !== null && $alnum !== '') {
            $add($alnum);
            $add(strtoupper($alnum));
        }

        $digits = preg_replace('/\D/', '', $s) ?? '';
        if ($digits !== '') {
            $add($digits);
        }

        if (strlen($digits) === 12) {
            $ean13 = $this->ean13From12($digits);
            if ($ean13 !== null) {
                $add($ean13);
            }
        }

        return array_keys($set);
    }

    /**
     * Searches products by barcode/product_code across all candidates.
     * Does NOT check item_serials — serial lookup requires location filtering
     * and should be done separately by the caller.
     */
    protected function findProductByBarcodeCandidates(array $candidates): ?Product
    {
        foreach ($candidates as $c) {
            $norm = strtolower(trim($c));
            if ($norm === '') {
                continue;
            }

            $product = Product::query()
                ->where(function ($q) use ($norm) {
                    $q->whereRaw("LOWER(TRIM(COALESCE(barcode, ''))) = ?", [$norm])
                      ->orWhereRaw("LOWER(TRIM(COALESCE(product_code, ''))) = ?", [$norm]);
                })
                ->first();

            if ($product) {
                return $product;
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
            $d    = (int) $ch;
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $twelve . $check;
    }

    /**
     * Resolve a scanned barcode to a product, and to a specific serial when the
     * barcode is a serial number. Three passes, in priority order:
     *   1. item_serial.serial_number, scoped to the location and in-stock
     *   2. products.barcode / products.product_code
     *   3. receiving_details.scanned_barcode — the label applied at PO receiving,
     *      which is not stored on the product row
     *
     * Do NOT relax the in-stock filter in pass 1: IssuanceScanController and
     * FifoPickingService depend on it, and it is what stops FIFO enforcement
     * accepting a unit that is not actually issuable. Callers that must match a
     * unit in any state (incident reports) use resolveScanAnyState() instead,
     * which wraps this method rather than loosening it.
     *
     * @return array{product: ?\App\Models\Product, serial: ?\App\Models\ItemSerial, matched_by: ?string}
     */
    protected function resolveScan(string $rawBarcode, int $locationId): array
    {
        $candidates = $this->barcodeLookupCandidates($rawBarcode);

        foreach ($candidates as $candidate) {
            $serial = ItemSerial::issuableAt($locationId, $candidate)->first();
            if ($serial) {
                return [
                    'product'    => Product::with('applianceDetail.brand')->find($serial->product_id),
                    'serial'     => $serial,
                    'matched_by' => 'serial',
                ];
            }
        }

        $product = $this->findProductByBarcodeCandidates($candidates);
        if ($product) {
            $product->load(['installationMaterialDetail.unit']);

            return ['product' => $product, 'serial' => null, 'matched_by' => 'product_barcode'];
        }

        foreach ($candidates as $candidate) {
            $norm = strtolower(trim($candidate));
            if ($norm === '') {
                continue;
            }

            $detail = \Illuminate\Support\Facades\DB::table('receiving_details')
                ->whereRaw('LOWER(TRIM(COALESCE(scanned_barcode,\'\'))) = ?', [$norm])
                ->whereNotNull('product_id')
                ->select('product_id')
                ->first();

            if ($detail) {
                return [
                    'product'    => Product::with(['applianceDetail.brand', 'installationMaterialDetail.unit'])
                                           ->find($detail->product_id),
                    'serial'     => null,
                    'matched_by' => 'receiving_barcode',
                ];
            }
        }

        return ['product' => null, 'serial' => null, 'matched_by' => null];
    }

    /**
     * Same as resolveScan(), but finds a serial in ANY state.
     *
     * resolveScan() matches serials via ItemSerial::issuableAt(), which requires
     * the unit to be in stock. That strictness is load-bearing for the pickers —
     * it is what stops FIFO enforcement accepting a unit that is not actually
     * issuable — so it is NOT relaxed here. This wrapper tries the strict path
     * first and only then falls back.
     *
     * Incidents need the looser match because a filer reporting theft, damage or
     * a return is usually reporting a unit that is precisely NOT in stock.
     *
     * A fallback hit reports matched_by 'serial_any_state', never 'serial', so a
     * caller can distinguish "in stock and issuable" from "found in some unknown
     * state" without re-querying status_id.
     *
     * @return array{product: ?\App\Models\Product, serial: ?\App\Models\ItemSerial, matched_by: ?string}
     */
    protected function resolveScanAnyState(string $rawBarcode, int $locationId): array
    {
        $strict = $this->resolveScan($rawBarcode, $locationId);

        // Only these two strict outcomes are trustworthy enough to skip the
        // any-state pass. receiving_barcode is NOT: InventoryScanTransactionController
        // writes the same scanned value to receiving_details.scanned_barcode AND to
        // item_serial.serial_number, so every serialised unit's serial is also a
        // receiving barcode — letting that pass win would shadow the serial lookup
        // for precisely the not-in-stock units incidents are about.
        //
        // The product_barcode pass must keep its precedence. Installation materials do get an
        // item_serial row, but since the receiving work its serial_number holds a
        // minted INT-… tag and the scanned supplier code lives in the separate
        // supplier_barcode column. The any-state pass below keys on serial_number
        // alone, so a scanned supplier barcode identifies a product, never one
        // specific unit — running product_barcode first is what keeps it resolving
        // that way instead of falling through to a unit-level lookup that cannot
        // legitimately match it.
        if ($strict['matched_by'] === 'serial' || $strict['matched_by'] === 'product_barcode') {
            return $strict;
        }

        foreach ($this->barcodeLookupCandidates($rawBarcode) as $candidate) {
            $norm = trim($candidate);
            if ($norm === '') {
                continue;
            }

            // Location-scoped, but with no status or serial_type filter. Ordered
            // so the newest row wins: there is no unique key on
            // (location_id, serial_number), and with serial_type unfiltered a
            // location can hold both a 'received' and a 'transferred' row.
            $serial = ItemSerial::where('location_id', $locationId)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($norm)])
                ->orderByDesc('serial_id')
                ->first();

            if ($serial) {
                return [
                    'product'    => Product::with('applianceDetail.brand')->find($serial->product_id),
                    'serial'     => $serial,
                    'matched_by' => 'serial_any_state',
                ];
            }
        }

        return $strict;   // the receiving_barcode product match, or a clean miss
    }
}
