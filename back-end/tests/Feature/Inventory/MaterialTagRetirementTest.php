<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Customer;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Issuance;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\User;
use App\Services\IncidentItemService;
use App\Services\InventoryBatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A digital tag names a physical package of installation material. Once the
 * batch behind it is spent the package is gone, so the tag must stop reading
 * "In Stock" and stop being pickable - but it must not be deleted, because the
 * receiving trail is the whole reason the tag exists.
 *
 * Retirement is batch-level, never per piece: we cannot know which box a picker
 * took, so retiring "the oldest" tag would kill the label on a box still on the
 * shelf.
 */
class MaterialTagRetirementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function makeMaterial(): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 1,
            'package_unit' => 'Roll', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product;
    }

    private function makePoDetail(Location $location, Product $product): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 100,
            'unit_price' => 5, 'subtotal' => 500,
        ]);
    }

    /**
     * Receive $packages packages of a fresh material by count (which mints one
     * tag per package) and hand back everything the assertions need.
     *
     * @return array{0:User,1:Location,2:Product,3:InventoryBatch}
     */
    private function receiveByCount(int $packages): array
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial();
        $poDetail = $this->makePoDetail($location, $product);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => $packages,
        ])->assertOk();

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->whereNotNull('receiving_id')
            ->firstOrFail();

        // The receiving hazard, pinned: batch accumulation happens before tags
        // are minted, so nothing may retire a tag at birth.
        $this->assertSame(
            $packages,
            ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'received')->count(),
            'Receiving must leave every minted tag live.'
        );

        return [$user, $location, $product, $batch];
    }

    /** @return string[] serial_status per tag, as the inventory detail panel would show it. */
    private function tagStatuses(User $user, Location $location, Product $product): array
    {
        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        return collect($records)->pluck('serial_status')->all();
    }

    /** The reported bug: a fully consumed tag kept advertising itself as In Stock. */
    public function test_exhausting_a_batch_retires_its_tags(): void
    {
        [$user, $location, $product, $batch] = $this->receiveByCount(4);

        (new InventoryBatchService())->deductFifo($product->product_id, $location->location_id, 4);

        $this->assertSame(0, (int) $batch->fresh()->quantity_remaining);
        $types = ItemSerial::where('batch_id', $batch->batch_id)->pluck('serial_type')->all();
        $this->assertSame(['issued', 'issued', 'issued', 'issued'], $types);

        $statuses = $this->tagStatuses($user, $location, $product);
        $this->assertCount(4, $statuses);
        foreach ($statuses as $status) {
            $this->assertSame('Issued', $status, 'A spent tag must not read In Stock.');
        }

        // The row survives - deleting it would destroy the receiving audit trail.
        $this->assertSame(4, ItemSerial::where('batch_id', $batch->batch_id)->count());
    }

    /**
     * Batch exhaustion is the only trigger. Retiring proportionally as pieces
     * drain would strand a physical box on the shelf with a dead label.
     */
    public function test_partially_consumed_batch_keeps_its_tags_live(): void
    {
        [$user, $location, $product, $batch] = $this->receiveByCount(4);

        (new InventoryBatchService())->deductFifo($product->product_id, $location->location_id, 3);

        $this->assertSame(1, (int) $batch->fresh()->quantity_remaining);
        $types = ItemSerial::where('batch_id', $batch->batch_id)->pluck('serial_type')->unique()->values()->all();
        $this->assertSame(['received'], $types);

        foreach ($this->tagStatuses($user, $location, $product) as $status) {
            $this->assertSame('In Stock', $status);
        }
    }

    /** A reverted issuance puts the stock back, so the tags come back with it. */
    public function test_restoring_stock_revives_the_tags(): void
    {
        [$user, $location, $product, $batch] = $this->receiveByCount(3);
        $service = new InventoryBatchService();

        $service->deductFifo($product->product_id, $location->location_id, 3);
        $this->assertSame(3, ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'issued')->count());

        $service->restoreFifo($product->product_id, $location->location_id, 3, $batch->receiving_id);

        $this->assertSame(3, (int) $batch->fresh()->quantity_remaining);
        $this->assertSame(3, ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'received')->count());

        foreach ($this->tagStatuses($user, $location, $product) as $status) {
            $this->assertSame('In Stock', $status);
        }
    }

    /** scopeIssuableAt() requires serial_type 'received', so a retired tag drops out. */
    public function test_retired_tag_is_not_pickable(): void
    {
        [, $location, $product, $batch] = $this->receiveByCount(2);
        $serialNumber = ItemSerial::where('batch_id', $batch->batch_id)->value('serial_number');

        $this->assertNotNull(
            ItemSerial::issuableAt($location->location_id, $serialNumber)->first(),
            'Sanity: the tag is pickable while stock remains.'
        );

        (new InventoryBatchService())->deductFifo($product->product_id, $location->location_id, 2);

        $this->assertNull(ItemSerial::issuableAt($location->location_id, $serialNumber)->first());
    }

    /**
     * File an incident naming the whole of $batch, and hand back the item so the
     * caller can decide its outcome.
     */
    private function quarantineWholeBatch(User $user, Location $location, Product $product, InventoryBatch $batch, int $quantity): array
    {
        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'reason' => 'defective', 'quantity' => $quantity, 'narrative' => 'test',
            'reported_by' => $user->user_id, 'status_id' => IncidentReport::statusId('Open'),
        ]);

        $items = app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $product->product_id,
            'batch_id'   => $batch->batch_id,
            'quantity'   => $quantity,
        ]]);

        return [$incident, $items[0]];
    }

    /**
     * IncidentItemService writes quantity_remaining directly rather than going
     * through InventoryBatchService, so it needed wiring of its own. Without it
     * scrapping the last of a batch left its tags reading In Stock - the exact
     * symptom the client reported, reached by a different door.
     */
    public function test_scrapping_the_last_of_a_batch_via_an_incident_retires_its_tags(): void
    {
        [$user, $location, $product, $batch] = $this->receiveByCount(3);

        [$incident, $item] = $this->quarantineWholeBatch($user, $location, $product, $batch, 3);

        // Quarantine deducts nothing - the goods are still on the shelf, made
        // unpickable by batch exclusion rather than by draining the batch - so
        // the tags must still read In Stock at this point.
        $this->assertSame(3, (int) $batch->fresh()->quantity_remaining);
        foreach ($this->tagStatuses($user, $location, $product) as $status) {
            $this->assertSame('In Stock', $status, 'Quarantine alone must not retire a tag.');
        }

        app(IncidentItemService::class)->setOutcome($item, IncidentItem::OUTCOME_SCRAP, null);
        app(IncidentItemService::class)->applyOutcomes($incident, $user->user_id);

        $this->assertSame(0, (int) $batch->fresh()->quantity_remaining);
        $this->assertSame(3, ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'issued')->count());

        foreach ($this->tagStatuses($user, $location, $product) as $status) {
            $this->assertSame('Issued', $status, 'Scrapped-out stock must not read In Stock.');
        }
    }

    /**
     * Retirement by incident is not a one-way trap: when stock lands back on
     * that batch the tags come back with it, exactly as after a reverted
     * issuance.
     */
    public function test_stock_returning_to_an_incident_drained_batch_revives_its_tags(): void
    {
        [$user, $location, $product, $batch] = $this->receiveByCount(3);

        [$incident, $item] = $this->quarantineWholeBatch($user, $location, $product, $batch, 3);
        app(IncidentItemService::class)->setOutcome($item, IncidentItem::OUTCOME_SCRAP, null);
        app(IncidentItemService::class)->applyOutcomes($incident, $user->user_id);

        $this->assertSame(3, ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'issued')->count());

        (new InventoryBatchService())
            ->restoreFifo($product->product_id, $location->location_id, 3, $batch->receiving_id);

        $this->assertSame(3, (int) $batch->fresh()->quantity_remaining);
        $this->assertSame(3, ItemSerial::where('batch_id', $batch->batch_id)->where('serial_type', 'received')->count());

        foreach ($this->tagStatuses($user, $location, $product) as $status) {
            $this->assertSame('In Stock', $status);
        }
    }

    /**
     * Collateral-damage guard. An appliance's item_serial row is batch-linked
     * too, but its lifecycle is hard-delete-on-issue / recreate-on-revert. If
     * reconciliation ever reached it, issuing would flip its serial_type
     * instead - or worse, revive a serial the issuance deleted.
     */
    public function test_appliance_serials_are_untouched_by_reconciliation(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $product  = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type 1.5HP',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 1, 'available_quantity' => 1, 'reorder_level' => 0,
        ]);

        $receiving = Receiving::create([
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now(),
        ]);
        $batch = (new InventoryBatchService())
            ->createBatch($product->product_id, $location->location_id, $receiving->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => $receiving->receiving_id, 'batch_id' => $batch->batch_id,
            'serial_number' => $sn, 'serial_type' => 'received', 'status_id' => 26,
        ]);

        // Direct call against an appliance batch: nothing may be retired even
        // when the batch is later exhausted.
        (new InventoryBatchService())->reconcileTagsForBatch($batch->batch_id);
        $this->assertSame('received', ItemSerial::where('serial_number', $sn)->value('serial_type'));

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);
        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 1,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $sn,
        ])->assertJson(['success' => true, 'action' => 'applied']);

        // The existing appliance path still hard-deletes; reconciliation neither
        // resurrected the row nor left a retired stand-in behind.
        $this->assertSame(0, (int) $batch->fresh()->quantity_remaining);
        $this->assertNull(ItemSerial::where('serial_number', $sn)->first());
        $this->assertTrue(DB::table('issuance_details')
            ->where('issuance_id', $issuance->issuance_id)
            ->where('serial_number', $sn)->exists(), 'The issued serial is still recorded on the detail line.');
    }
}
