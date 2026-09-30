<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use App\Services\ReceivingPostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User, 3: PurchaseOrderDetail} */
    private function scaffold(float $poUnitPrice = 300.00): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material', 'cost_price' => 99.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'led_' . uniqid(), 'first_name' => 'Led', 'last_name' => 'Ger',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 50,
            'unit_price' => $poUnitPrice, 'subtotal' => $poUnitPrice * 50,
        ]);

        return [$location, $product, $user, $poDetail];
    }

    public function test_receiving_posts_one_in_row_valued_at_the_po_line_price(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(300.00);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 10, 'counted', $poDetail, null, null, $user->user_id
        ));

        $rows = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $result['receiving']->receiving_id)
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('in', $row->direction);
        $this->assertSame('receiving', $row->movement_type);
        $this->assertSame(10, $row->quantity);
        $this->assertSame('300.00', $row->unit_cost);
        $this->assertSame('3000.00', $row->extended_value);
        $this->assertSame('actual', $row->cost_basis);
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertSame((int) $product->product_id, (int) $row->product_id);
        $this->assertSame((int) $user->user_id, (int) $row->actor_user_id);
        $this->assertSame((int) $result['batch']->batch_id, (int) $row->batch_id);
    }

    public function test_the_batch_records_the_cost_the_stock_arrived_at(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(275.25);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 4, 'counted', $poDetail, null, null, $user->user_id
        ));

        $batch = InventoryBatch::find($result['batch']->batch_id);

        $this->assertSame('275.25', $batch->unit_cost);
        $this->assertSame('actual', $batch->cost_basis);
    }

    public function test_the_receiving_line_price_comes_from_the_po_not_the_product_list_cost(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(300.00);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 2, 'counted', $poDetail, null, null, $user->user_id
        ));

        $price = DB::table('receiving_details')
            ->where('receiving_id', $result['receiving']->receiving_id)
            ->value('prod_price');

        $this->assertSame(300.00, (float) $price, 'prod_price must be what the PO says we pay, not the product list cost.');
    }

    public function test_two_posts_of_the_same_line_produce_two_rows_not_one_doubled_row(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold();

        $first  = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 3, 'counted', $poDetail, null, null, $user->user_id
        ));
        DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 5, 'counted', $poDetail, null, null, $user->user_id
        ));

        $rows = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $first['receiving']->receiving_id)
            ->orderBy('ledger_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([3, 5], $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertCount(2, $rows->pluck('posting_key')->unique());
    }
}
