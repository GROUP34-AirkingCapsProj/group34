<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftStagingTest extends TestCase
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

    /** A package-counted consumable: 1 box = 50 pieces. */
    private function makeConsumable(int $piecesPerPackage = 50, string $countUnit = 'package'): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $piecesPerPackage,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);

        return $product->fresh();
    }

    private function makePoLine(Product $product, Location $location, int $quantityOrdered = 10): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $quantityOrdered,
            'unit_price' => 5, 'subtotal' => 5 * $quantityOrdered,
        ]);
    }

    public function test_open_draft_lookup_finds_only_open_drafts_for_that_po_and_location(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $poDetail = $this->makePoLine($product, $location);
        $poId     = (int) $poDetail->po_id;

        $open = ReceivingDraft::create([
            'po_id'       => $poId,
            'location_id' => $location->location_id,
            'opened_by'   => $user->user_id,
            'status'      => 'open',
        ]);

        $found = ReceivingDraft::openFor($poId, (int) $location->location_id);
        $this->assertNotNull($found);
        $this->assertSame((int) $open->draft_id, (int) $found->draft_id);

        // A submitted draft must not be resumed — that delivery is done.
        $open->update(['status' => 'submitted']);
        $this->assertNull(ReceivingDraft::openFor($poId, (int) $location->location_id));
    }

    public function test_draft_items_relate_to_their_draft_and_cascade_on_delete(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $poDetail = $this->makePoLine($product, $location);

        $draft = ReceivingDraft::create([
            'po_id'       => $poDetail->po_id,
            'location_id' => $location->location_id,
            'opened_by'   => $user->user_id,
            'status'      => 'open',
        ]);

        ReceivingDraftItem::create([
            'draft_id'          => $draft->draft_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail->po_detail_id,
            'entry_mode'        => 'scanned',
            'scanned_barcode'   => 'BOX-' . uniqid(),
            'quantity_amount'   => 50,
            'packages_received' => 1,
            'pieces_expected'   => 50,
            'contents_verified' => false,
            'units_entered'     => 1,
        ]);

        $this->assertSame(1, $draft->fresh()->items()->count());
        $this->assertSame(50, (int) $draft->fresh()->stagedPieces());

        $draftId = (int) $draft->draft_id;
        $draft->delete();

        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draftId)->count());
    }
}
