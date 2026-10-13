<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
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

    /** A package-counted installation material: 1 box = 50 pieces. */
    private function makeInstallationMaterial(int $piecesPerPackage = 50, string $countUnit = 'package'): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
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
        $product  = $this->makeInstallationMaterial();
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
        $product  = $this->makeInstallationMaterial();
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

    public function test_opening_a_draft_twice_resumes_the_same_one(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial();
        $poDetail = $this->makePoLine($product, $location);

        $payload = ['po_id' => $poDetail->po_id, 'location_id' => $location->location_id];

        $first  = $this->actingAs($user)->postJson('/api/receiving-drafts', $payload);
        $first->assertOk();
        $firstId = $first->json('data.draft_id');

        $second = $this->actingAs($user)->postJson('/api/receiving-drafts', $payload);
        $second->assertOk();

        $this->assertSame($firstId, $second->json('data.draft_id'), 'A second clerk must resume the open draft, not start a rival list.');
    }

    public function test_staging_a_scan_records_a_row_and_moves_no_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $res = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-A',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'staged');
        $this->assertSame(50, (int) $res->json('data.item.quantity_amount'));
        $this->assertSame(1, (int) $res->json('data.item.packages_received'));

        $this->assertNull(
            \App\Models\Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
    }

    public function test_one_material_scan_stages_the_full_order_quantity(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $res = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-A',
            'quantity'     => 500,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'staged');
        $this->assertSame(500, (int) $res->json('data.item.quantity_amount'));
        $this->assertSame(10, (int) $res->json('data.item.packages_received'));

        $this->assertNull(
            \App\Models\Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
    }

    public function test_staging_a_count_marks_contents_verified(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $res = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id'  => $poDetail->po_detail_id,
            'entry_mode'    => 'counted',
            'quantity'      => 2,
            'actual_pieces' => 95,
        ]);

        $res->assertOk();
        // Counting IS the verification — the clerk had the pieces in hand.
        $this->assertTrue((bool) $res->json('data.item.contents_verified'));
        $this->assertSame(95, (int) $res->json('data.item.quantity_amount'));
        $this->assertSame(100, (int) $res->json('data.item.pieces_expected'));
        $this->assertSame(2, (int) $res->json('data.item.packages_received'));
    }

    public function test_a_staged_row_can_be_adjusted_and_removed(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $itemId = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-B',
        ])->json('data.item.draft_item_id');

        $patch = $this->actingAs($user)->patchJson("/api/receiving-drafts/{$draft}/items/{$itemId}", [
            'actual_pieces' => 47,
        ]);
        $patch->assertOk();
        $this->assertSame(47, (int) $patch->json('data.item.quantity_amount'));
        // The expectation must NOT move — the shortfall is the whole point.
        $this->assertSame(50, (int) $patch->json('data.item.pieces_expected'));

        $delete = $this->actingAs($user)->deleteJson("/api/receiving-drafts/{$draft}/items/{$itemId}");
        $delete->assertOk();
        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draft)->count());
    }

    public function test_a_duplicate_barcode_is_rejected_at_stage_time(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $body = [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-SAME',
        ];

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", $body)->assertOk();
        $second = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", $body);

        $second->assertOk();
        $second->assertJsonPath('data.action', 'rejected');
        $this->assertSame(1, ReceivingDraftItem::where('draft_id', $draft)->count());
    }

    public function test_discarding_a_draft_removes_its_rows(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-C',
        ])->assertOk();

        $this->actingAs($user)->deleteJson("/api/receiving-drafts/{$draft}")->assertOk();

        $this->assertSame('discarded', ReceivingDraft::find($draft)->status);
        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draft)->count());
        // The barcode is free again, so the goods can be re-received.
        $this->assertNull(ReceivingDraft::openFor((int) $poDetail->po_id, (int) $location->location_id));
    }

    public function test_po_scan_status_reports_an_open_draft(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeInstallationMaterial(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-BADGE',
        ])->assertOk();

        $res = $this->actingAs($user)->getJson("/api/receivings/po-scan-status/{$poDetail->po_id}");

        $res->assertOk();
        $this->assertSame(1, (int) $res->json('data.open_draft.staged_count'));
        $this->assertSame(50, (int) $res->json('data.open_draft.staged_pieces'));
    }
}
