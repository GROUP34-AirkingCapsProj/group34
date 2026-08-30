<?php

namespace Tests\Feature\Inventory;

use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * scopeIssuableAt() must resolve a unit by EITHER identifier it carries.
 *
 * A consumable's supplier barcode moved out of serial_number (which now holds
 * a generated INT-... tag) into supplier_barcode. Matching serial_number alone
 * made those scans fall through to the product-level fallback, losing the
 * batch identification FIFO depends on.
 */
class IssuableAtSupplierBarcodeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Scope Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
    }

    private function makeUnit(Location $loc, Product $p, string $serial, ?string $supplier): ItemSerial
    {
        return ItemSerial::create([
            'product_id'       => $p->product_id,
            'location_id'      => $loc->location_id,
            'serial_number'    => $serial,
            'supplier_barcode' => $supplier,
            'serial_type'      => 'received',
            'status_id'        => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    public function test_both_identifiers_resolve_to_the_same_row(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeProduct();

        $tag      = 'INT-' . strtoupper(uniqid());
        $supplier = 'SUP-' . strtoupper(uniqid());
        $unit     = $this->makeUnit($loc, $p, $tag, $supplier);

        $byTag = ItemSerial::issuableAt((int) $loc->location_id, $tag)->first();
        $bySup = ItemSerial::issuableAt((int) $loc->location_id, $supplier)->first();

        $this->assertNotNull($byTag, 'the generated tag must resolve');
        $this->assertNotNull($bySup, 'the supplier barcode must resolve');
        $this->assertSame((int) $unit->serial_id, (int) $byTag->serial_id);
        $this->assertSame((int) $unit->serial_id, (int) $bySup->serial_id);
    }

    public function test_matching_is_case_and_space_insensitive_on_the_supplier_barcode(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeProduct();

        $supplier = 'SUP-' . strtoupper(uniqid());
        $unit     = $this->makeUnit($loc, $p, 'INT-' . uniqid(), $supplier);

        $found = ItemSerial::issuableAt((int) $loc->location_id, '  ' . strtolower($supplier) . ' ')->first();

        $this->assertNotNull($found);
        $this->assertSame((int) $unit->serial_id, (int) $found->serial_id);
    }

    /**
     * The OR sits in its own closure — proving the other three conditions still
     * AND against it. Without the nesting, a supplier-barcode match anywhere
     * would leak past the location filter.
     */
    public function test_a_supplier_barcode_at_another_location_does_not_resolve(): void
    {
        $here      = $this->makeLocation();
        $elsewhere = $this->makeLocation();
        $p         = $this->makeProduct();

        $supplier = 'SUP-' . strtoupper(uniqid());
        $this->makeUnit($elsewhere, $p, 'INT-' . uniqid(), $supplier);

        $this->assertNull(ItemSerial::issuableAt((int) $here->location_id, $supplier)->first());
        $this->assertNotNull(ItemSerial::issuableAt((int) $elsewhere->location_id, $supplier)->first());
    }

    public function test_a_shipped_out_or_out_of_stock_unit_does_not_resolve_by_supplier_barcode(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeProduct();

        $shipped = 'SUP-' . strtoupper(uniqid());
        $unit    = $this->makeUnit($loc, $p, 'INT-' . uniqid(), $shipped);
        $unit->update(['serial_type' => 'shipped']);

        $this->assertNull(ItemSerial::issuableAt((int) $loc->location_id, $shipped)->first());

        $sold = 'SUP-' . strtoupper(uniqid());
        $out  = $this->makeUnit($loc, $p, 'INT-' . uniqid(), $sold);
        $out->update(['status_id' => 27]);

        $this->assertNull(ItemSerial::issuableAt((int) $loc->location_id, $sold)->first());
    }

    public function test_an_empty_scan_never_matches_a_null_or_blank_supplier_barcode(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeProduct();

        $this->makeUnit($loc, $p, 'INT-' . uniqid(), null);
        $this->makeUnit($loc, $p, 'INT-' . uniqid(), '');
        $this->makeUnit($loc, $p, 'INT-' . uniqid(), '   ');

        $this->assertNull(ItemSerial::issuableAt((int) $loc->location_id, '')->first());
        $this->assertNull(ItemSerial::issuableAt((int) $loc->location_id, '   ')->first());
    }
}
