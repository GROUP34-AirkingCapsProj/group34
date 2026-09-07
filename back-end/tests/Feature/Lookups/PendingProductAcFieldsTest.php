<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\Location;
use App\Models\PendingProduct;
use App\Models\UnitLookup;

/**
 * The registration form does NOT post to ProductController — it posts to
 * pendingProductsAPI.store, so an appliance registration lands in the
 * approval queue as an appliance_snapshot and only becomes an
 * appliance_details row when someone approves it.
 *
 * That gives the two new fields two places to be silently dropped:
 *
 *   1. storeAppliance() persists $validated['appliance'], and validate()
 *      returns validated keys only — a key with no rule is stripped before
 *      it ever reaches appliance_snapshot.
 *   2. approveAppliance()'s ApplianceDetail::create() has to name the
 *      columns explicitly; anything it omits is lost even if the snapshot
 *      carried it.
 *
 * So the only assertion that proves anything here is the full round trip:
 * POST -> snapshot -> approve -> appliance_details.
 *
 * Writing that round trip also surfaced a separate, pre-existing break: the
 * approve path wrote pending_products.created_product_id, a column that does
 * not exist in either database (it is named product_id). Every approval —
 * appliance and installation material alike — died with SQLSTATE[42S22] and rolled the
 * whole transaction back, so no product was ever created. pending_products
 * is empty in production, so nobody had hit it yet. Fixed alongside, because
 * the approval half of these tests cannot run otherwise.
 */
class PendingProductAcFieldsTest extends LookupTestCase
{
    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Pending Test Loc ' . uniqid(),
            'location_type' => 'warehouse',
        ]);
    }

    private function brand(): BrandLookup
    {
        return BrandLookup::create(['brand_name' => 'Brand ' . uniqid()]);
    }

    private function unit(): UnitLookup
    {
        return UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u',
        ]);
    }

    /**
     * The registration payload the modal actually sends, minus whatever the
     * caller wants to vary in the appliance block.
     */
    private function payload(array $applianceOverrides = []): array
    {
        return [
            'registration_kind' => 'appliance',
            'barcode'           => 'BC-' . uniqid(),
            'unit_price'        => 25000,
            'cost_price'        => 19000,
            'appliance'         => array_merge([
                'product_name'           => 'Pending Split ' . uniqid(),
                'model_code'             => 'MC-' . uniqid(),
                'capacity_rating'        => '1.5HP',
                'variant'                => 'Inverter',
                'category_id'            => $this->category()->category_id,
                'brand_id'               => $this->brand()->brand_id,
                'unit_id'                => $this->unit()->unit_id,
                'warranty_period_months' => 36,
                'initial_location_id'    => $this->location()->location_id,
            ], $applianceOverrides),
        ];
    }

    private function acType(): AcTypeLookup
    {
        return AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
    }

    public function test_a_submitted_registration_keeps_the_ac_fields_in_its_snapshot(): void
    {
        $this->actingAsAdmin();

        $type = $this->acType();

        $response = $this->postJson('/api/pending-products', $this->payload([
            'ac_type_id' => $type->ac_type_id,
            'unit_role'  => 'indoor',
        ]));

        $response->assertStatus(201);

        $pending = PendingProduct::findOrFail($response->json('data.pending_product_id'));

        $this->assertSame(
            $type->ac_type_id,
            $pending->appliance_snapshot['ac_type_id'] ?? null,
            'ac_type_id was stripped out of appliance_snapshot — it has no validation rule in storeAppliance()'
        );
        $this->assertSame(
            'indoor',
            $pending->appliance_snapshot['unit_role'] ?? null,
            'unit_role was stripped out of appliance_snapshot — it has no validation rule in storeAppliance()'
        );
    }

    public function test_approving_a_registration_writes_the_ac_fields_onto_the_appliance(): void
    {
        $this->actingAsAdmin();

        $type = $this->acType();

        $submit = $this->postJson('/api/pending-products', $this->payload([
            'ac_type_id' => $type->ac_type_id,
            'unit_role'  => 'outdoor',
        ]));
        $submit->assertStatus(201);

        $pendingId = $submit->json('data.pending_product_id');

        $approve = $this->postJson("/api/pending-products/{$pendingId}/approve", [
            'final_product_name' => 'Approved Split ' . uniqid(),
        ]);
        $approve->assertStatus(200);

        $detail = ApplianceDetail::where('product_id', $approve->json('data.product_id'))->firstOrFail();

        $this->assertSame($type->ac_type_id, $detail->ac_type_id);
        $this->assertSame('outdoor', $detail->unit_role);
    }

    public function test_an_invalid_unit_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/pending-products', $this->payload([
            'unit_role' => 'sideways',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('appliance.unit_role');
    }

    public function test_an_unknown_ac_type_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/pending-products', $this->payload([
            'ac_type_id' => 999999,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('appliance.ac_type_id');
    }

    /**
     * Not every appliance is an aircon. Omitting both fields has to keep
     * working exactly as it did before this task, storing nulls.
     */
    public function test_omitting_both_fields_still_registers_and_approves_with_nulls(): void
    {
        $this->actingAsAdmin();

        $submit = $this->postJson('/api/pending-products', $this->payload());
        $submit->assertStatus(201);

        $approve = $this->postJson("/api/pending-products/{$submit->json('data.pending_product_id')}/approve", [
            'final_product_name' => 'Plain Appliance ' . uniqid(),
        ]);
        $approve->assertStatus(200);

        $detail = ApplianceDetail::where('product_id', $approve->json('data.product_id'))->firstOrFail();

        $this->assertNull($detail->ac_type_id);
        $this->assertNull($detail->unit_role);
    }
}
