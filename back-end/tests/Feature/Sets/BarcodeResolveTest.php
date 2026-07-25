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

    public function test_unknown_barcode_resolves_to_nothing(): void
    {
        $loc = $this->makeLocation();

        $out = $this->resolver()->call('NOPE-' . uniqid(), $loc->location_id);

        $this->assertNull($out['product']);
        $this->assertNull($out['serial']);
        $this->assertNull($out['matched_by']);
    }
}
