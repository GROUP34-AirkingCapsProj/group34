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

    private function ids(array $query): array
    {
        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue?' . http_build_query($query));

        $res->assertOk();

        return collect($res->json('data'))->pluck('serial_id')->all();
    }

    public function test_it_filters_by_product_type(): void
    {
        $material  = $this->tag($this->material());
        $appliance = $this->tag($this->appliance());

        $ids = $this->ids(['product_type' => 'installation_material']);

        $this->assertContains($material->serial_id, $ids);
        $this->assertNotContains($appliance->serial_id, $ids);
    }

    public function test_it_filters_by_the_date_the_stock_came_in(): void
    {
        $location = $this->location();
        $march    = $this->receiptOn('2026-03-10', $location);
        $august   = $this->receiptOn('2026-08-20', $location);

        $old   = $this->tag($this->material(), ['receiving_id' => $march->receiving_id]);
        $fresh = $this->tag($this->material(), ['receiving_id' => $august->receiving_id]);

        $ids = $this->ids(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

        $this->assertContains($fresh->serial_id, $ids);
        $this->assertNotContains($old->serial_id, $ids);
    }

    /* A date filter asks "what came in that week". A tag with no receipt
       cannot answer, and guessing either way would be a lie — so it drops
       out of a date-filtered list and stays in an unfiltered one. */
    public function test_a_tag_with_no_receipt_drops_out_of_a_date_filter(): void
    {
        $orphan = $this->tag($this->material(), ['receiving_id' => null]);

        $this->assertContains($orphan->serial_id, $this->ids([]));
        $this->assertNotContains($orphan->serial_id, $this->ids(['date_from' => '2026-01-01']));
    }

    public function test_it_filters_by_location(): void
    {
        $here      = $this->location('Cebu');
        $elsewhere = $this->location('Davao');

        $mine   = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $theirs = $this->tag($this->material(), ['location_id' => $elsewhere->location_id]);

        $ids = $this->ids(['location_id' => $here->location_id]);

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
    }

    public function test_it_filters_by_receipt_number(): void
    {
        $location = $this->location();
        $wanted   = $this->receiptOn('2026-09-01', $location);
        $other    = $this->receiptOn('2026-09-01', $location);

        $mine   = $this->tag($this->material(), ['receiving_id' => $wanted->receiving_id]);
        $theirs = $this->tag($this->material(), ['receiving_id' => $other->receiving_id]);

        $ids = $this->ids(['receipt' => $wanted->receiving_number]);

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
    }

    public function test_it_searches_by_product_name_and_code(): void
    {
        $copper = $this->tag($this->material());
        $ac     = $this->tag($this->appliance());

        $ids = $this->ids(['q' => 'Copper']);

        $this->assertContains($copper->serial_id, $ids);
        $this->assertNotContains($ac->serial_id, $ids);
    }

    /* The product_code half of the search box exists so a clerk who has the
       code memorized (not the display name) can still find the tag. A search
       that only matched product_name would look identical on the "Copper"
       test above while silently dropping this case. */
    public function test_it_searches_by_product_code_alone(): void
    {
        $product = $this->material();
        $tag     = $this->tag($product);

        $ids = $this->ids(['q' => $product->product_code]);

        $this->assertContains($tag->serial_id, $ids);
    }

    public function test_it_filters_by_whether_the_tag_was_sent_to_a_printer(): void
    {
        $unsent = $this->tag($this->material());
        $sent   = $this->tag($this->material(), [
            'print_count'      => 1,
            'first_printed_at' => now()->subDay(),
            'last_printed_at'  => now()->subDay(),
        ]);

        $unsentIds = $this->ids(['print_state' => 'unsent']);
        $this->assertContains($unsent->serial_id, $unsentIds);
        $this->assertNotContains($sent->serial_id, $unsentIds);

        $sentIds = $this->ids(['print_state' => 'sent']);
        $this->assertContains($sent->serial_id, $sentIds);
        $this->assertNotContains($unsent->serial_id, $sentIds);
    }

    /* "1st to 31st" must include stock received exactly on the 1st and
       exactly on the 31st — the realistic case a clerk hits, and the one an
       off-by-one on either boundary (`>` instead of `>=`, `<` instead of
       `<=`) would silently drop. */
    public function test_date_filter_includes_both_boundary_days(): void
    {
        $location = $this->location();
        $onFrom   = $this->receiptOn('2026-08-01', $location);
        $onTo     = $this->receiptOn('2026-08-31', $location);
        $before   = $this->receiptOn('2026-07-31', $location);
        $after    = $this->receiptOn('2026-09-01', $location);

        $first = $this->tag($this->material(), ['receiving_id' => $onFrom->receiving_id]);
        $last  = $this->tag($this->material(), ['receiving_id' => $onTo->receiving_id]);
        $early = $this->tag($this->material(), ['receiving_id' => $before->receiving_id]);
        $late  = $this->tag($this->material(), ['receiving_id' => $after->receiving_id]);

        $ids = $this->ids(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

        $this->assertContains($first->serial_id, $ids);
        $this->assertContains($last->serial_id, $ids);
        $this->assertNotContains($early->serial_id, $ids);
        $this->assertNotContains($late->serial_id, $ids);
    }

    /* Filters narrow together, not in competition: "appliances received in
       August" must not return August materials. */
    public function test_filters_combine(): void
    {
        $location = $this->location();
        $august   = $this->receiptOn('2026-08-20', $location);
        $march    = $this->receiptOn('2026-03-20', $location);

        $wanted     = $this->tag($this->appliance(), ['receiving_id' => $august->receiving_id]);
        $wrongType  = $this->tag($this->material(), ['receiving_id' => $august->receiving_id]);
        $wrongMonth = $this->tag($this->appliance(), ['receiving_id' => $march->receiving_id]);

        $ids = $this->ids([
            'product_type' => 'appliance',
            'date_from'    => '2026-08-01',
            'date_to'      => '2026-08-31',
        ]);

        $this->assertContains($wanted->serial_id, $ids);
        $this->assertNotContains($wrongType->serial_id, $ids);
        $this->assertNotContains($wrongMonth->serial_id, $ids);
    }

    /* The filter bar reads "340 tags · 112 not yet sent" on every load.
       Two requests for one sentence would be a wasted round trip. */
    public function test_it_reports_the_matching_and_unsent_totals(): void
    {
        $location = $this->location('CountingHouse');
        $product  = $this->material();

        $this->tag($product, ['location_id' => $location->location_id]);
        $this->tag($product, ['location_id' => $location->location_id]);
        $this->tag($product, [
            'location_id'     => $location->location_id,
            'print_count'     => 1,
            'last_printed_at' => now(),
        ]);
        /* Deliberately out of step: print_count says "never sent" but
           last_printed_at says otherwise. last_printed_at is THE authoritative
           column, so this row must count as sent, not unsent — pinning which
           column the endpoint actually trusts. */
        $this->tag($product, [
            'location_id'     => $location->location_id,
            'print_count'     => 0,
            'last_printed_at' => now(),
        ]);

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue?location_id=' . $location->location_id);

        $res->assertOk();
        $res->assertJsonPath('counts.total', 4);
        $res->assertJsonPath('counts.unsent', 2);
    }

    public function test_select_all_returns_the_ids_matching_the_filters(): void
    {
        $here  = $this->location('Here');
        $there = $this->location('There');

        $mine   = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $theirs = $this->tag($this->material(), ['location_id' => $there->location_id]);

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue/ids?location_id=' . $here->location_id);

        $res->assertOk();

        $ids = $res->json('data.serial_ids');

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
        $this->assertFalse($res->json('data.truncated'));
    }

    /* A silent truncation reads as "everything is selected". The clerk finds
       out otherwise at the shelf, with 140 unlabelled cartons. */
    public function test_select_all_caps_at_two_hundred_and_says_so(): void
    {
        $location = $this->location('BigDelivery');
        $product  = $this->material();

        foreach (range(1, 205) as $ignored) {
            $this->tag($product, ['location_id' => $location->location_id]);
        }

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue/ids?location_id=' . $location->location_id);

        $res->assertOk();

        $this->assertCount(200, $res->json('data.serial_ids'));
        $this->assertSame(205, $res->json('data.total'));
        $this->assertTrue($res->json('data.truncated'));
        $this->assertSame(200, $res->json('data.max'));
    }
}
