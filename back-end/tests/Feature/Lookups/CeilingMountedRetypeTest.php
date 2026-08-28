<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The ordering bug in 000003/000004 and the correction that repairs it.
 *
 * 000003 backfills ac_type_id before 000004 adds the Ceiling Mounted type, so
 * on a fresh run every ceiling-mounted unit is typed Split Type — its
 * description truthfully says "split-type", because a ceiling-mounted unit is
 * a split system. 000007 repoints them.
 *
 * This was not caught before production because the test database had its
 * ac_type_id column cleared and the backfill re-run by hand AFTER Ceiling
 * Mounted existed, which is not the order a real deployment uses.
 */
class CeilingMountedRetypeTest extends LookupTestCase
{
    private function retype(): void
    {
        $ceilingMountedId = AcTypeLookup::where('ac_type_name', 'Ceiling Mounted')->value('ac_type_id');
        $splitTypeId      = AcTypeLookup::where('ac_type_name', 'Split Type')->value('ac_type_id');

        DB::table('appliance_details')
            ->where('ac_type_id', $splitTypeId)
            ->whereRaw('LOWER(variant) LIKE ?', ['%ceiling mounted%'])
            ->update(['ac_type_id' => $ceilingMountedId]);
    }

    private function applianceWithVariant(string $variant, string $typeName): ApplianceDetail
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Retype Test ' . uniqid(),
            'product_type' => 'appliance',
            'description'  => 'a split-type air conditioner',
        ]);

        return ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'variant'       => $variant,
            'ac_type_id'    => AcTypeLookup::where('ac_type_name', $typeName)->value('ac_type_id'),
            'unit_role'     => 'complete',
            'is_serialized' => true,
        ]);
    }

    public function test_a_ceiling_mounted_unit_mistyped_as_split_is_repointed(): void
    {
        $detail = $this->applianceWithVariant('Ceiling Mounted Inverter', 'Split Type');

        $this->retype();

        $expected = AcTypeLookup::where('ac_type_name', 'Ceiling Mounted')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_the_non_inverter_spelling_is_repointed_too(): void
    {
        $detail = $this->applianceWithVariant('Ceiling Mounted Non-Inverter', 'Split Type');

        $this->retype();

        $expected = AcTypeLookup::where('ac_type_name', 'Ceiling Mounted')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_a_genuine_split_type_is_left_alone(): void
    {
        $detail = $this->applianceWithVariant('R32 Super Inverter', 'Split Type');

        $splitTypeId = AcTypeLookup::where('ac_type_name', 'Split Type')->value('ac_type_id');

        $this->retype();

        $this->assertSame($splitTypeId, $detail->fresh()->ac_type_id);
    }

    public function test_a_hand_corrected_row_is_not_clobbered(): void
    {
        // Someone already moved this ceiling-mounted unit to Cassette by hand.
        // The correction must not drag it back — it only touches rows still
        // pointing at Split Type.
        $detail = $this->applianceWithVariant('Ceiling Mounted Inverter', 'Cassette');

        $cassetteId = AcTypeLookup::where('ac_type_name', 'Cassette')->value('ac_type_id');

        $this->retype();

        $this->assertSame($cassetteId, $detail->fresh()->ac_type_id);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $detail = $this->applianceWithVariant('Ceiling Mounted Inverter', 'Split Type');

        $this->retype();
        $first = $detail->fresh()->ac_type_id;

        $this->retype();

        $this->assertSame($first, $detail->fresh()->ac_type_id);
    }
}
