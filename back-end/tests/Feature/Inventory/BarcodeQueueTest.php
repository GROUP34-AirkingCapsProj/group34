<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tag population a clerk prints from.
 *
 * This is a reading endpoint over item_serial. It mints nothing and changes
 * nothing — its whole job is to let someone find the labels they need among
 * everything that has ever been received.
 */
class BarcodeQueueTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function location(string $name = 'Main'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    private function material(): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product;
    }

    private function appliance(): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        return $product;
    }

    private function receiptOn(string $date, Location $location): Receiving
    {
        return Receiving::create([
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => $date,
        ]);
    }

    private function tag(Product $product, array $overrides = []): ItemSerial
    {
        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'TAG-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));
    }

    /** A non-centralized user pinned to one location. */
    private function scopedUserAt(Location $location): User
    {
        $user = $this->user();
        $user->role_id     = 3; // branch_manager
        $user->location_id = $location->location_id;
        $user->save();

        return $user;
    }

    public function test_it_lists_a_tag_with_its_tier_and_print_state(): void
    {
        $tag = $this->tag($this->material());

        $res = $this->actingAs($this->user())->getJson('/api/barcode-labels/queue');

        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('serial_id', $tag->serial_id);

        $this->assertNotNull($row, 'the tag must appear in the queue');
        $this->assertSame('minted', $row['tier']);
        $this->assertSame($tag->serial_number, $row['serial_number']);
        $this->assertSame('Copper Tube', $row['product_name']);
        $this->assertSame(0, $row['print_count']);
        $this->assertNull($row['last_printed_at']);
    }

    /* Every identity a receipt produced belongs here. Leaving the supplier
       and manufacturer tiers out is exactly the gap this page exists to
       close: a clerk holding a smudged supplier label could not look it up. */
    public function test_it_lists_supplier_codes_and_manufacturer_serials_too(): void
    {
        $supplier = $this->tag($this->material(), ['supplier_barcode' => 'SUP-9911']);
        $maker    = $this->tag($this->appliance());

        $rows = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->keyBy('serial_id');

        $this->assertSame('supplier', $rows[$supplier->serial_id]['tier']);
        $this->assertSame('manufacturer', $rows[$maker->serial_id]['tier']);
    }

    /* A retired tag names stock that has left. No mode can print one, so
       offering it in a printing queue would be an invitation to a dead end. */
    public function test_it_omits_retired_tags(): void
    {
        $retired = $this->tag($this->material(), ['serial_type' => 'issued']);

        $ids = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertNotContains($retired->serial_id, $ids);
    }

    public function test_it_requires_a_signed_in_user(): void
    {
        $this->getJson('/api/barcode-labels/queue')->assertStatus(401);
    }

    /* A branch-scoped user prints labels for their own stock. Listing another
       branch's tags would let them select-all and run off labels for cartons
       they will never see — and the "select all matching" endpoint resolves
       ids server-side, so the scope has to hold there too, not just here. */
    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->location('Mine');
        $theirs = $this->location('Theirs');

        $ours   = $this->tag($this->material(), ['location_id' => $mine->location_id]);
        $others = $this->tag($this->material(), ['location_id' => $theirs->location_id]);

        $scoped = $this->scopedUserAt($mine);

        $ids = collect($this->actingAs($scoped)->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertContains($ours->serial_id, $ids);
        $this->assertNotContains($others->serial_id, $ids);

        $selectAll = $this->actingAs($scoped)->getJson('/api/barcode-labels/queue/ids')->json('data.serial_ids');

        $this->assertContains($ours->serial_id, $selectAll);
        $this->assertNotContains($others->serial_id, $selectAll);
    }

    /* A centralized role is deliberately unscoped — head office prints for
       every branch. Without this, "scope it" would quietly become "scope it
       for everyone" and nobody would notice until head office could not
       print. */
    public function test_a_centralized_user_sees_every_location(): void
    {
        $here  = $this->location('Here');
        $there = $this->location('There');

        $a = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $b = $this->tag($this->material(), ['location_id' => $there->location_id]);

        $ids = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertContains($a->serial_id, $ids);
        $this->assertContains($b->serial_id, $ids);
    }
}
