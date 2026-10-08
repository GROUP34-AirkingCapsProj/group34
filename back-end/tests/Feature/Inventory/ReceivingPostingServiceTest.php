<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Services\ReceivingPostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPostingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    /** @return array{0: Product, 1: Location, 2: PurchaseOrder, 3: PurchaseOrderDetail} */
    private function makeMaterialSetup(int $ppp = 100, int $orderedPackages = 10): array
    {
        $location = $this->makeLocation();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        // Re-fetch with installationMaterialDetail loaded so count_unit reads correctly.
        $product = Product::with('installationMaterialDetail')->find($product->product_id);

        return [$product, $location, $po, $poDetail];
    }

    private function makeApplianceSetup(): array
    {
        $location = $this->makeLocation();
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type Aircon',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id' => $product->product_id, 'is_serialized' => 1,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 1,
            'unit_price' => 500, 'subtotal' => 500,
        ]);

        $product = Product::with('applianceDetail')->find($product->product_id);

        return [$product, $location, $po, $poDetail];
    }

    public function test_check_overage_returns_null_when_quantity_fits(): void
    {
        [$product, , , $poDetail] = $this->makeMaterialSetup(50, 1);

        $result = app(ReceivingPostingService::class)->checkOverage($poDetail, $product, 50);

        $this->assertNull($result);
    }

    public function test_check_overage_describes_the_overage(): void
    {
        [$product, , , $poDetail] = $this->makeMaterialSetup(50, 1);

        $result = app(ReceivingPostingService::class)->checkOverage($poDetail, $product, 55);

        $this->assertSame('over_receipt', $result['action']);
        $this->assertSame(50, $result['ordered_pieces']);
        $this->assertSame(0, $result['already_received']);
        $this->assertSame(50, $result['remaining']);
        $this->assertSame(55, $result['counted']);
        $this->assertSame(5, $result['overage']);
    }

    public function test_post_writes_inventory_batch_detail_and_tags_together(): void
    {
        [$product, $location, , $poDetail] = $this->makeMaterialSetup(100, 10);

        $result = DB::transaction(function () use ($product, $location, $poDetail) {
            return app(ReceivingPostingService::class)->post(
                $product,
                $location->location_id,
                473,
                'counted',
                $poDetail,
                null,
                'ZONE-A',
                null,
                473
            );
        });

        $this->assertSame(473, $result['pieces']);
        $this->assertSame(473, $result['batch']->quantity_remaining);
        $this->assertSame('ZONE-A', $result['batch']->fresh()->zone_label);

        $detail = ReceivingDetail::where('receiving_id', $result['receiving']->receiving_id)->first();
        $this->assertSame('counted', $detail->entry_mode);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(473, $inventory->quantity_on_hand);
        $this->assertSame(4, $inventory->packages_on_hand);

        $this->assertCount(1, $result['tags'], 'Piece granularity must mint exactly one lot tag.');
    }

    public function test_repeated_posts_accumulate_into_one_batch(): void
    {
        [$product, $location, , $poDetail] = $this->makeMaterialSetup(100, 10);
        $service = app(ReceivingPostingService::class);

        $first = DB::transaction(fn () => $service->post(
            $product, $location->location_id, 100, 'counted', $poDetail, null, null, null, 100
        ));
        $second = DB::transaction(fn () => $service->post(
            $product, $location->location_id, 50, 'counted', $poDetail, null, null, null, 50
        ));

        $this->assertSame($first['batch']->batch_id, $second['batch']->batch_id);
        $this->assertSame(150, $second['batch']->fresh()->quantity_remaining);
    }

    public function test_scan_path_records_one_row_keyed_by_the_supplier_code(): void
    {
        [$product, $location, , $poDetail] = $this->makeMaterialSetup(100, 10);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 1, 'scanned', $poDetail, 'SUPPLIER-BC-999'
        ));

        $this->assertCount(1, $result['tags']);
        $tag = $result['tags'][0];
        $this->assertSame('SUPPLIER-BC-999', $tag->supplier_barcode);
        // Design change: a generated barcode exists only because an item
        // arrived without one. A scanned supplier code IS the identifier now,
        // so serial_number carries the scanned code verbatim instead of a
        // separately-minted INT- tag.
        $this->assertSame('SUPPLIER-BC-999', $tag->serial_number);
    }

    public function test_serialized_product_keeps_its_scanned_serial(): void
    {
        [$product, $location, , $poDetail] = $this->makeApplianceSetup();

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 1, 'scanned', $poDetail, 'SN-APPLIANCE-0001'
        ));

        $this->assertCount(1, $result['tags']);
        $tag = $result['tags'][0];
        $this->assertInstanceOf(ItemSerial::class, $tag);
        $this->assertSame(
            'SN-APPLIANCE-0001',
            $tag->serial_number,
            'An appliance must keep the exact scanned serial — warranty lookup and incident '
                . 'reports resolve units by this exact string, so minting an INT- lot tag instead '
                . 'would silently break them.'
        );
        $this->assertFalse(
            str_starts_with($tag->serial_number, 'INT-'),
            'A serialized appliance must never be given a generated INT- lot tag in place of its own serial.'
        );
    }
}
