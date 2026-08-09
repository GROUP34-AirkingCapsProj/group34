<?php

namespace Tests\Feature\Sets;

use App\Models\ItemSerial;
use Illuminate\Support\Facades\DB;

/**
 * The incident lookup must find a serial in ANY state. A filer reporting theft
 * or damage is usually reporting a unit that is precisely NOT in stock, so the
 * strict issuableAt() matching used by the pickers would report "not found"
 * for the exact cases incidents exist to capture.
 */
class IncidentScanLookupTest extends SetTestCase
{
    /** Exposes the protected trait methods for testing. */
    private function resolver(): object
    {
        return new class {
            use \App\Traits\NormalizesBarcode;

            public function callAnyState(string $barcode, int $locationId): array
            {
                return $this->resolveScanAnyState($barcode, $locationId);
            }

            public function callStrict(string $barcode, int $locationId): array
            {
                return $this->resolveScan($barcode, $locationId);
            }
        };
    }

    public function test_it_finds_a_serial_that_is_not_in_stock(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $serial = $this->makeSerial($p, $loc, 'SN-GONE-001', $b->receiving_id);
        // 28 = "Out of Stock" — a unit issuableAt() will refuse to match.
        $serial->update(['status_id' => 28]);

        $r = $this->resolver();

        // The strict resolver misses it — that is correct and must stay so.
        $this->assertNull($r->callStrict('SN-GONE-001', (int) $loc->location_id)['serial']);

        // The incident resolver finds it.
        $hit = $r->callAnyState('SN-GONE-001', (int) $loc->location_id);
        $this->assertNotNull($hit['serial']);
        $this->assertSame('SN-GONE-001', $hit['serial']->serial_number);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
        $this->assertSame('serial_any_state', $hit['matched_by']);
    }

    /**
     * Production shape: InventoryScanTransactionController writes the SAME scanned
     * string to receiving_details.scanned_barcode and to item_serial.serial_number
     * in one transaction, so every serialised unit's serial is also a receiving
     * barcode. resolveScan()'s third pass therefore HITS for a not-in-stock unit,
     * returning the product with serial => null. If the any-state wrapper
     * short-circuits on any non-null product, that receiving_barcode match shadows
     * the serial lookup for precisely the units incidents exist to capture.
     *
     * makeSerial() alone creates no receiving_details row, so the other tests in
     * this file structurally cannot catch this.
     */
    public function test_a_receiving_barcode_twin_does_not_shadow_the_serial(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $barcode = 'SN-TWIN-001';

        $serial = $this->makeSerial($p, $loc, $barcode, $b->receiving_id);
        $serial->update(['status_id' => 28]);   // stolen/damaged — no longer in stock

        // The twin row the receiving scan would have written alongside the serial.
        DB::table('receiving_details')->insert([
            'receiving_id'    => $b->receiving_id,
            'product_id'      => $p->product_id,
            'quantity_amount' => 1,
            'prod_price'      => $p->cost_price ?? 0,
            'condition'       => 'Good',
            'scanned_barcode' => $barcode,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $r = $this->resolver();

        // The strict resolver falls through to its receiving_barcode pass and
        // loses the unit identity — that is the trap.
        $strict = $r->callStrict($barcode, (int) $loc->location_id);
        $this->assertSame('receiving_barcode', $strict['matched_by']);
        $this->assertNull($strict['serial']);

        // The incident resolver must still reach the serial.
        $hit = $r->callAnyState($barcode, (int) $loc->location_id);
        $this->assertNotNull($hit['serial'], 'receiving_barcode match shadowed the serial lookup');
        $this->assertSame($barcode, $hit['serial']->serial_number);
        $this->assertSame('serial_any_state', $hit['matched_by']);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
    }

    /**
     * A receiving barcode with no serial row behind it must still resolve to the
     * product — the fallback keeps working, it just no longer wins early.
     */
    public function test_a_receiving_barcode_without_a_serial_still_resolves_to_the_product(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        DB::table('receiving_details')->insert([
            'receiving_id'    => $b->receiving_id,
            'product_id'      => $p->product_id,
            'quantity_amount' => 1,
            'prod_price'      => $p->cost_price ?? 0,
            'condition'       => 'Good',
            'scanned_barcode' => 'RCV-ONLY-001',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $hit = $this->resolver()->callAnyState('RCV-ONLY-001', (int) $loc->location_id);
        $this->assertSame('receiving_barcode', $hit['matched_by']);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
        $this->assertNull($hit['serial']);
    }

    public function test_an_in_stock_serial_still_resolves_through_the_strict_path(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $serial = $this->makeSerial($p, $loc, 'SN-HERE-001', $b->receiving_id);
        $serial->update(['status_id' => ItemSerial::IN_STOCK_STATUS_ID]);

        $hit = $this->resolver()->callAnyState('SN-HERE-001', (int) $loc->location_id);
        $this->assertSame('serial', $hit['matched_by']);
        $this->assertSame('SN-HERE-001', $hit['serial']->serial_number);
    }

    public function test_a_product_barcode_still_resolves(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-LOOKUP-01');

        $hit = $this->resolver()->callAnyState('BC-LOOKUP-01', (int) $loc->location_id);
        $this->assertSame('product_barcode', $hit['matched_by']);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
        $this->assertNull($hit['serial']);
    }

    public function test_an_unknown_barcode_returns_a_clean_miss(): void
    {
        $loc = $this->makeLocation();

        $hit = $this->resolver()->callAnyState('NOPE-NOTHING', (int) $loc->location_id);
        $this->assertNull($hit['product']);
        $this->assertNull($hit['serial']);
        $this->assertNull($hit['matched_by']);
    }

    public function test_a_serial_at_another_location_does_not_resolve(): void
    {
        $a = $this->makeLocation('A');
        $b = $this->makeLocation('B');
        $p = $this->makeAppliance();
        $batch = $this->makeBatch($p, $a, ['quantity_remaining' => 1, 'received_at' => now()]);

        $this->makeSerial($p, $a, 'SN-ELSEWHERE', $batch->receiving_id);

        // Location scoping must survive the loosened status matching.
        $hit = $this->resolver()->callAnyState('SN-ELSEWHERE', (int) $b->location_id);
        $this->assertNull($hit['serial']);
    }
}
