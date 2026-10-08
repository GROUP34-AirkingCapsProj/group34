<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use App\Services\ReceivingDraftSubmitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftSubmitTest extends TestCase
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

    private function makeInstallationMaterial(int $piecesPerPackage = 50): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
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
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

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

    private function makeDraft(PurchaseOrder $po, Location $location, User $user, ?string $zone = null): ReceivingDraft
    {
        return ReceivingDraft::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'opened_by' => $user->user_id, 'status' => 'open', 'zone_label' => $zone,
        ]);
    }

    private function stageBox(ReceivingDraft $draft, Product $product, PurchaseOrderDetail $line, string $barcode, int $pieces = 50): ReceivingDraftItem
    {
        return ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => $barcode, 'quantity_amount' => $pieces,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);
    }

    public function test_staging_alone_moves_no_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        // The central claim of this feature, asserted as bluntly as possible.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
        $this->assertSame(0, InventoryBatch::where('product_id', $product->product_id)->count());
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame(0, ItemSerial::where('product_id', $product->product_id)->count());
    }

    public function test_submitting_posts_every_staged_row(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user, 'A-03');

        foreach (['B1', 'B2', 'B3'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('submitted', $result['action']);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertNotNull($inventory);
        $this->assertSame(150, (int) $inventory->quantity_on_hand);

        $this->assertSame(3, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame(
            150,
            (int) ReceivingDetail::where('po_detail_id', $line->po_detail_id)->sum('quantity_amount')
        );

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();
        $this->assertNotNull($batch);
        $this->assertSame('A-03', $batch->zone_label, 'Items must inherit the header zone label.');

        $draft->refresh();
        $this->assertSame('submitted', $draft->status);
        $this->assertNotNull($draft->receiving_id);
        $this->assertNotNull($draft->submitted_at);
    }

    public function test_a_wrong_line_removed_before_submit_never_reaches_inventory(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $bulk     = $this->makeInstallationMaterial(50);
        $aircon   = $this->makeAppliance();
        $po       = $this->makePo($location);
        $bulkLine   = $this->makeLine($po, $bulk, $location, 10);
        $airconLine = $this->makeLine($po, $aircon, $location, 5);
        $draft      = $this->makeDraft($po, $location, $user);

        // The mistake: a TCL aircon staged against the bulk material line.
        $wrong = ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $bulk->product_id,
            'po_detail_id' => $bulkLine->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'TCL-889', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        // The clerk spots it on the review list and removes it.
        $wrong->delete();

        // Re-staged against the right line, as an appliance.
        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $aircon->product_id,
            'po_detail_id' => $airconLine->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'TCL-889', 'quantity_amount' => 1,
            'packages_received' => null, 'pieces_expected' => null,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);
        $this->assertSame('submitted', $result['action']);

        // No bulk stock exists at all.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $bulk->product_id)->first()
        );
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $bulkLine->po_detail_id)->count());

        // The aircon is in stock, with its own serial kept verbatim.
        $serial = ItemSerial::where('serial_number', 'TCL-889')->first();
        $this->assertNotNull($serial);
        $this->assertSame((int) $aircon->product_id, (int) $serial->product_id);
    }

    public function test_one_bad_row_rolls_the_whole_submit_back_and_keeps_the_draft(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $po       = $this->makePo($location);
        // 5 boxes ordered; the draft stages 6.
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('failed', $result['action']);
        $this->assertNotEmpty($result['failures']);

        // Nothing posted.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());

        // The draft survives intact, all six rows still there to be fixed.
        $draft->refresh();
        $this->assertSame('open', $draft->status);
        $this->assertNull($draft->receiving_id);
        $this->assertSame(6, $draft->items()->count());
    }

    public function test_accepting_the_overage_lets_the_same_draft_through(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, true);

        $this->assertSame('submitted', $result['action']);
        $this->assertSame(
            300,
            (int) ReceivingDetail::where('po_detail_id', $line->po_detail_id)->sum('quantity_amount')
        );
    }

    public function test_a_serial_taken_between_staging_and_submit_fails_the_whole_submit(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $aircon   = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $aircon, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $aircon->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'RACE-001', 'quantity_amount' => 1,
            'packages_received' => null, 'pieces_expected' => null,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        // Another clerk's draft submits first and claims the serial.
        ItemSerial::create([
            'product_id' => $aircon->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'RACE-001', 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('failed', $result['action']);
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame('open', $draft->fresh()->status);
    }
}
