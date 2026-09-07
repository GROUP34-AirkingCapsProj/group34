<?php

namespace Tests\Feature\Settings;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LabelPrinterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A printer per location, set by an admin rather than by a developer.
 *
 * Two halves: storing the map on SystemSetting, and the rule that decides
 * which of those printers a given job goes to. They belong together because
 * the second is the only reason the first is worth having — a job goes to the
 * printer at the location of the tags being printed, since a label exists to
 * be stuck on a box and the box is at that location.
 */
class LabelPrinterSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> every spool file handed out, cleaned up together. */
    private array $spoolFiles = [];

    protected function tearDown(): void
    {
        /* In tearDown rather than at the end of each test: an assertion that
           fails ends the test body early, and a leaked temp file per failing
           run is a slow mess nobody attributes to this file. */
        foreach ($this->spoolFiles as $file) {
            @unlink($file);
        }

        $this->spoolFiles = [];

        parent::tearDown();
    }

    private function location(string $name = 'Warehouse A'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function tagAt(Location $location): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    /** A file stands in for a printer share: the transport writes bytes either way. */
    private function spoolFor(Location $location): string
    {
        $file = tempnam(sys_get_temp_dir(), 'zpl');

        $this->spoolFiles[] = $file;

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'share', 'share' => $file,
        ]);

        return $file;
    }

    public function test_a_location_with_nothing_stored_reads_as_unset(): void
    {
        $location = $this->location();

        $printer = SystemSetting::labelPrinter($location->location_id);

        $this->assertSame('', $printer['transport']);
        $this->assertSame(9100, $printer['port']);
    }

    public function test_it_stores_and_reads_back_one_location(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'tcp', 'host' => '192.168.1.50', 'port' => 9100, 'timeout' => 5,
        ]);

        $printer = SystemSetting::labelPrinter($location->location_id);

        $this->assertSame('tcp', $printer['transport']);
        $this->assertSame('192.168.1.50', $printer['host']);
    }

    /* Pins the map-level merge: saving one location must not disturb another. */
    public function test_saving_one_location_leaves_another_alone(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        SystemSetting::setLabelPrinter($a->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);
        SystemSetting::setLabelPrinter($b->location_id, ['transport' => 'tcp', 'host' => '10.0.0.2']);

        $this->assertSame('10.0.0.1', SystemSetting::labelPrinter($a->location_id)['host']);
        $this->assertSame('10.0.0.2', SystemSetting::labelPrinter($b->location_id)['host']);
    }

    /* Empty transport is a saved state, not an absence: it is how an admin
       retires a printer, and it must survive a round trip as "off". The
       entry is replaced wholesale, so the old host must not survive either -
       a partial save must not be able to resurrect it. */
    public function test_a_location_can_be_saved_as_having_no_printer(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);
        SystemSetting::setLabelPrinter($location->location_id, ['transport' => '']);

        $printer = SystemSetting::labelPrinter($location->location_id);
        $this->assertSame('', $printer['transport']);
        $this->assertSame('', $printer['host']);
    }

    /* Every other setter in this file normalises on write. A settings form
       posts numbers as strings, and a strict comparison against the int
       default would otherwise fail depending on whether a location was ever
       configured. */
    public function test_a_port_arriving_as_a_string_reads_back_as_an_int(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'port' => '9100']);

        $this->assertSame(9100, SystemSetting::labelPrinter($location->location_id)['port']);
    }

    /* JSON round-trips a whole float back as an int, so a float timeout
       would split int/string depending on whether the value was fractional.
       An int-only field removes the ambiguity at the source. */
    public function test_a_timeout_arriving_as_a_string_reads_back_as_an_int(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'timeout' => '5']);

        $this->assertSame(5, SystemSetting::labelPrinter($location->location_id)['timeout']);
    }

    /* THE test for this feature. A resolver that read the user's location, or
       simply took the first configured printer, passes every other case in
       this file and fails only here — with labels coming out in a building
       nobody is standing in. */
    public function test_a_tag_prints_at_its_own_location_not_another(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);
        $spoolB = $this->spoolFor($b);

        $tag = $this->tagAt($b);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertOk();

        $this->assertSame('', file_get_contents($spoolA), 'Warehouse A must not have printed');
        $this->assertStringContainsString($tag->serial_number, file_get_contents($spoolB));

    }

    public function test_a_batch_spanning_two_locations_is_refused(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);
        $this->spoolFor($b);

        $here  = $this->tagAt($a);
        $there = $this->tagAt($b);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$here->serial_id, $there->serial_id],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('2 locations', $res->json('message'));

        /* Nothing left for the printer, so nothing may claim it did. */
        $this->assertSame('', file_get_contents($spoolA));
        $this->assertSame(0, (int) $here->fresh()->print_count);
        $this->assertNull($here->fresh()->last_printed_at);
    }

    public function test_a_location_with_no_printer_says_which_one(): void
    {
        $location = $this->location('Warehouse B');
        $tag      = $this->tagAt($location);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertStatus(503);
        $this->assertStringContainsString('Warehouse B', $res->json('message'));
        $this->assertSame(0, (int) $tag->fresh()->print_count);
    }

    /* The location set is computed after the policy filter, not from the ids
       the client sent. A supplier code at another location is dropped by the
       policy before it can make this batch look mixed. */
    public function test_a_tag_the_policy_drops_cannot_make_a_batch_look_mixed(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);

        $mine     = $this->tagAt($a);
        $supplier = $this->tagAt($b);
        $supplier->update(['supplier_barcode' => 'SUP-' . uniqid()]);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$mine->serial_id, $supplier->serial_id],
            'mode'       => 'bulk',
        ]);

        $res->assertOk();
        $this->assertStringContainsString($mine->serial_number, file_get_contents($spoolA));
    }

    /* item_serial.location_id is nullable. A null would cast to location 0 and
       send staff off to configure a printer for a location the tag does not
       have — a dead end. The tag is what needs fixing, so say that. */
    public function test_a_tag_with_no_location_says_the_tag_is_the_problem(): void
    {
        $a   = $this->location('Warehouse A');
        $tag = $this->tagAt($a);

        $this->spoolFor($a);

        /* Written past the model: location_id is guarded, and the point is a
           row that exists this way in the schema, not one Eloquent allows. */
        DB::table('item_serial')->where('serial_id', $tag->serial_id)->update(['location_id' => null]);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('no location recorded', $res->json('message'));
        $this->assertSame(0, (int) $tag->fresh()->print_count);
    }

    public function test_configured_is_answered_per_location(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $this->spoolFor($a);

        $printer = app(LabelPrinterService::class);

        $this->assertTrue($printer->isConfigured($a->location_id));
        $this->assertFalse($printer->isConfigured($b->location_id));
    }

    private function admin(): User
    {
        $user = $this->user();
        $user->role_id = 1; // admin
        $user->save();

        return $user;
    }

    /* Not $this->user(): that helper takes role_id = min(role_id), and the
       seeder always creates admin first as id 1, so it hands back an admin.
       Anything asserting an admin-only gate needs this instead. */
    private function nonAdmin(): User
    {
        $user = $this->user();
        $user->role_id = (int) (DB::table('roles')->max('role_id') ?? 2);
        $user->save();

        return $user;
    }

    public function test_it_lists_every_location_with_its_printer(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        SystemSetting::setLabelPrinter($a->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);

        $res = $this->actingAs($this->admin())->getJson('/api/system/label-printers');

        $res->assertOk();

        $rows = collect($res->json('data.locations'));

        $this->assertTrue($rows->firstWhere('location_id', $a->location_id)['configured']);
        /* Listed even though nobody has set it up — the panel exists to show
           which warehouses still need one. */
        $this->assertFalse($rows->firstWhere('location_id', $b->location_id)['configured']);
    }

    public function test_it_reports_the_label_stock_it_expects(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/system/label-printers');

        $res->assertOk();
        $this->assertSame(100, $res->json('data.label.width_mm'));
        $this->assertSame(25, $res->json('data.label.height_mm'));
    }

    public function test_an_admin_can_save_one_location(): void
    {
        $location = $this->location();

        $res = $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", [
                'transport' => 'tcp', 'host' => '192.168.1.50', 'port' => 9100, 'timeout' => 5,
            ]);

        $res->assertOk();
        $this->assertSame('192.168.1.50', SystemSetting::labelPrinter($location->location_id)['host']);
    }

    public function test_a_non_admin_cannot_save(): void
    {
        $location = $this->location();

        $res = $this->actingAs($this->nonAdmin())
            ->putJson("/api/system/label-printers/{$location->location_id}", [
                'transport' => 'tcp', 'host' => '192.168.1.50',
            ]);

        $res->assertForbidden();
        $this->assertSame('', SystemSetting::labelPrinter($location->location_id)['transport']);
    }

    public function test_a_network_printer_needs_an_address(): void
    {
        $location = $this->location();

        $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", ['transport' => 'tcp'])
            ->assertStatus(422);
    }

    public function test_a_share_printer_needs_a_path(): void
    {
        $location = $this->location();

        $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", ['transport' => 'share'])
            ->assertStatus(422);
    }

    /* An unknown location means a stale page or a hand-made request. Writing
       it anyway would leave settings under an id nothing lists, which nobody
       could then find or remove. */
    public function test_saving_an_unknown_location_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/system/label-printers/99999999', ['transport' => ''])
            ->assertNotFound();
    }
    /* The first real attempt will look exactly like this: an address nothing
       is listening on. It must come back as a sentence, with a 200, because
       the panel renders the message rather than an error state. */
    public function test_an_unreachable_printer_answers_with_a_message(): void
    {
        $location = $this->location('Warehouse A');

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'tcp',
            'host'      => '127.0.0.1',
            'port'      => 9101, // nothing listens here
            'timeout'   => 1,
        ]);

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertFalse($res->json('data.ok'));
        $this->assertStringContainsString('127.0.0.1:9101', $res->json('data.message'));
    }

    public function test_testing_a_location_with_no_printer_says_so(): void
    {
        $location = $this->location('Warehouse B');

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertFalse($res->json('data.ok'));
        $this->assertStringContainsString('No printer is set up', $res->json('data.message'));
    }

    /* A writable path is reachable, which is what the share transport means.
       The only success path assertable without hardware. */
    public function test_a_writable_share_reads_as_reachable(): void
    {
        $location = $this->location('Warehouse A');
        $this->spoolFor($location);

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertTrue($res->json('data.ok'));
    }

    public function test_a_non_admin_cannot_test_a_printer(): void
    {
        $location = $this->location();

        $this->actingAs($this->nonAdmin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test")
            ->assertForbidden();
    }

    public function test_testing_an_unknown_location_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/system/label-printers/99999999/test')
            ->assertNotFound();
    }

    /* A closed branch does not need a printer offered for it, and this list
       must not disagree with the one behind the print button, which filters
       the same way. is_active is not fillable, so it is set past the guard
       rather than through update(). */
    public function test_it_leaves_out_inactive_locations(): void
    {
        $open   = $this->location('Open Warehouse');
        $closed = $this->location('Closed Warehouse');
        $closed->forceFill(['is_active' => 0])->save();

        $ids = collect($this->actingAs($this->admin())
            ->getJson('/api/system/label-printers')
            ->json('data.locations'))->pluck('location_id')->all();

        $this->assertContains($open->location_id, $ids);
        $this->assertNotContains($closed->location_id, $ids);
    }
}
