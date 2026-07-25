<?php

namespace App\Traits;

use App\Models\ItemSerial;
use App\Models\Product;

trait NormalizesBarcode
{
    /**
     * Returns a deduplicated list of candidate strings to try for a given
     * raw barcode: original, upper, lower, no-whitespace, alphanumeric-only,
     * digits-only, and EAN-13 derived from a 12-digit UPC-A.
     */
    protected function barcodeLookupCandidates(string $raw): array
    {
        $s   = trim($raw);
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
            $product->load(['consumableDetail.unit']);

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
                    'product'    => Product::with(['applianceDetail.brand', 'consumableDetail.unit'])
                                           ->find($detail->product_id),
                    'serial'     => null,
                    'matched_by' => 'receiving_barcode',
                ];
            }
        }

        return ['product' => null, 'serial' => null, 'matched_by' => null];
    }
}
