<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use App\Services\ReceivingDraftStagingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftValidationTest extends TestCase
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

    private function makeConsumable(int $piecesPerPackage = 50): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $piecesPerPackage,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product->fresh();
    }

    private function makeAppliance(): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'TCL Aircon 1.5HP',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id' => $product->product_id, 'is_serialized' => 1,
        ]);

        return $product->fresh();
    }

    private function makePo(Location $location): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
    }

    private function makeLine(PurchaseOrder $po, Product $product, Location $location, int $ordered): PurchaseOrderDetail
    {
        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $ordered,
            'unit_price' => 5, 'subtotal' => 5 * $ordered,
        ]);
    }

    private function makeDraft(PurchaseOrder $po, Location $location, User $user): ReceivingDraft
    {
        return ReceivingDraft::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'opened_by' => $user->user_id, 'status' => 'open',
        ]);
    }

    public function test_a_barcode_already_staged_in_an_open_draft_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'DUPE-001', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 50, 1, 'DUPE-001');

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('already', strtolower($result['reason']));
    }

    public function test_a_barcode_already_in_item_serial_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'TCL-SEEN-001', 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 1, null, 'TCL-SEEN-001');

        $this->assertSame('rejected', $result['action']);
    }

    public function test_a_serialized_product_without_a_barcode_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 1, null, null);

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('scanned', strtolower($result['reason']));
    }

    public function test_a_line_belonging_to_another_po_is_refused(): void
    {
        $user      = $this->makeUser();
        $location  = $this->makeLocation();
        $product   = $this->makeConsumable();
        $poA       = $this->makePo($location);
        $poB       = $this->makePo($location);
        $lineOnB   = $this->makeLine($poB, $product, $location, 10);
        $draftOnA  = $this->makeDraft($poA, $location, $user);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draftOnA, $lineOnB, $product, 50, 1, null);

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('purchase order', strtolower($result['reason']));
    }

    public function test_an_overage_warns_but_does_not_block_staging(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $po       = $this->makePo($location);
        // Only 1 box ordered; the clerk is staging a second.
        $line     = $this->makeLine($po, $product, $location, 1);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'BOX-1', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 50, 1, 'BOX-2');

        $this->assertSame('allowed', $result['action'], 'Staging must never block an overage — the clerk needs to describe what is on the floor.');
        $this->assertNotNull($result['warning']);
        $this->assertSame('over_receipt', $result['warning']['action']);
    }

    public function test_validation_never_touches_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        $serialsBefore = ItemSerial::count();
        $batchesBefore = InventoryBatch::count();

        $service = app(ReceivingDraftStagingService::class);
        $service->check($draft, $line, $product, 50, 1, 'BOX-X');

        $this->assertSame($serialsBefore, ItemSerial::count());
        $this->assertSame($batchesBefore, InventoryBatch::count());
    }
}
