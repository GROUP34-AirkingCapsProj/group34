<?php

namespace Tests\Feature\Sets;

use App\Traits\NormalizesBarcode;
use Illuminate\Support\Facades\DB;

class BarcodeResolveTest extends SetTestCase
{
    /** Exposes the protected trait method for testing. */
    private function resolver(): object
    {
        return new class {
            use NormalizesBarcode;

            public function call(string $barcode, int $locationId): array
            {
                return $this->resolveScan($barcode, $locationId);
            }

            public function canonical(string $serial): string
            {
                return $this->canonicalSerial($serial);
            }
        };
    }

    public function test_resolves_an_appliance_by_serial_number(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeAppliance();
        $serial  = $this->makeSerial($product, $loc, 'SN-RESOLVE-1');
        DB::table('item_serial')->where('serial_id', $serial->serial_id)->update(['status_id' => 26]);

        $out = $this->resolver()->call('SN-RESOLVE-1', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
        $this->assertNotNull($out['serial']);
        $this->assertSame('serial', $out['matched_by']);
    }

    public function test_resolves_a_consumable_by_product_barcode(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable(barcode: 'BC-RESOLVE-2');

        $out = $this->resolver()->call('BC-RESOLVE-2', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
        $this->assertNull($out['serial']);
        $this->assertSame('product_barcode', $out['matched_by']);
    }

    public function test_barcode_matching_tolerates_case_and_whitespace(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable(barcode: 'BC-RESOLVE-3');

        $out = $this->resolver()->call('  bc-resolve-3 ', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
    }

    public function test_manual_and_camera_serials_use_the_same_canonical_value(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('SN-00111', $resolver->canonical("  SN-00 111\t"));
        $this->assertSame('1111', $resolver->canonical(" 11 11\r\n"));
    }

    public function test_unknown_barcode_resolves_to_nothing(): void
    {
        $loc = $this->makeLocation();

        $out = $this->resolver()->call('NOPE-' . uniqid(), $loc->location_id);

        $this->assertNull($out['product']);
        $this->assertNull($out['serial']);
        $this->assertNull($out['matched_by']);
    }

    /**
     * Pass 3: a code that is neither a serial nor on the product row, but was the
     * barcode scanned at PO receiving. This is the one path the other tests miss,
     * and it is a real production case — receiving-time labels live in
     * receiving_details.scanned_barcode, not on products.
     */
    public function test_resolves_by_the_receiving_scanned_barcode(): void
    {
        $loc     = $this->makeLocation();
        // Product's own barcode is something else, so passes 1 and 2 both miss.
        $product = $this->makeConsumable(barcode: 'PRODUCT-OWN-BC');

        DB::table('receiving_details')->insert([
            'product_id'      => $product->product_id,
            'scanned_barcode' => 'RCV-SCAN-ONLY',
            'quantity_amount' => 1,
            'prod_price'      => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $out = $this->resolver()->call('RCV-SCAN-ONLY', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
        $this->assertNull($out['serial']);
        $this->assertSame('receiving_barcode', $out['matched_by']);
    }
}
