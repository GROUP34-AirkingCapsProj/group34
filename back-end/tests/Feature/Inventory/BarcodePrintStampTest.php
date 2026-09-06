<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Print history on a tag.
 *
 * The stamp records "sent to the printer", never "stuck on a box" — a jam
 * still counts as sent. What must hold is that a failed send counts as
 * nothing at all, and that the first send keeps its date forever.
 *
 * This file pins the schema and the FK behaviour only. The stamping cases
 * themselves — a successful send, a failed one, a second send after the
 * first — are Task 4's; nothing here should be read as covering them yet.
 */
class BarcodePrintStampTest extends TestCase
{
    use DatabaseTransactions;

    private string $spoolFile;

    private Location $printerLocation;

    protected function setUp(): void
    {
        parent::setUp();

        /* A file stands in for the printer share: the service writes the job
           as bytes either way, so this exercises the real transport.

           One location for the whole file, with one printer: a job now goes
           to the printer where its tags are, so a helper that gave each tag
           its own location would have every multi-tag case refused as a
           mixed batch. */
        $this->spoolFile       = tempnam(sys_get_temp_dir(), 'zpl');
        $this->printerLocation = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L',
            'location_type' => 'warehouse',
        ]);

        SystemSetting::setLabelPrinter($this->printerLocation->location_id, [
            'transport' => 'share',
            'share'     => $this->spoolFile,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->spoolFile);
        parent::tearDown();
    }

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function materialTag(array $overrides = []): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->printerLocation->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));
    }

    /* Eloquent returns null for an attribute whose column does not exist, so
       asserting on the model alone would pass against a schema that never
       got the migration. Asking the schema directly is what makes this test
       able to fail. */
    public function test_the_print_history_columns_exist(): void
    {
        foreach (['first_printed_at', 'last_printed_at', 'print_count', 'last_printed_by'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('item_serial', $column),
                "item_serial is missing {$column}"
            );
        }
    }

    public function test_a_new_tag_reads_as_never_sent(): void
    {
        $tag = $this->materialTag();

        /* Read the raw row back rather than trusting the in-memory model —
           an unset attribute reads as null on the model regardless of
           whether the column (or its default) actually exists, so this
           would pass trivially against a schema missing the migration. */
        $row = DB::table('item_serial')->where('serial_id', $tag->serial_id)->first();

        $this->assertSame(0, (int) $row->print_count);
        $this->assertNull($row->first_printed_at);
        $this->assertNull($row->last_printed_at);
        $this->assertNull($row->last_printed_by);
    }

    public function test_the_model_can_write_and_cast_these_columns(): void
    {
        $tag = $this->materialTag();
        $writer = $this->user();

        $tag->update([
            'first_printed_at' => '2026-01-05 08:00:00',
            'last_printed_at'  => '2026-01-05 08:00:00',
            'print_count'      => 1,
            'last_printed_by'  => $writer->user_id,
        ]);

        $tag = $tag->fresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $tag->last_printed_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $tag->first_printed_at);
        $this->assertSame(1, $tag->print_count);
        $this->assertSame($writer->user_id, $tag->last_printed_by);
    }

    public function test_deleting_the_printing_user_blanks_attribution_without_touching_the_tag(): void
    {
        $writer = $this->user();
        $tag = $this->materialTag([
            'last_printed_by'  => $writer->user_id,
            'first_printed_at' => '2026-01-05 08:00:00',
            'last_printed_at'  => '2026-01-05 08:00:00',
            'print_count'      => 3,
        ]);

        /* User::class uses SoftDeletes — a plain delete() only sets
           deleted_at and never touches the row, so the FK's ON DELETE SET
           NULL would never fire and this test would pass without proving
           anything. forceDelete() actually removes the row and exercises
           the constraint. */
        $writer->forceDelete();

        $row = DB::table('item_serial')->where('serial_id', $tag->serial_id)->first();

        $this->assertNotNull($row, 'the tag itself must survive the user being deleted');
        $this->assertNull($row->last_printed_by);
        $this->assertSame(3, (int) $row->print_count);
    }

    public function test_a_successful_send_stamps_the_tag(): void
    {
        $tag  = $this->materialTag();
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $tag->refresh();

        $this->assertSame(1, (int) $tag->print_count);
        $this->assertNotNull($tag->first_printed_at);
        $this->assertNotNull($tag->last_printed_at);
        $this->assertSame($user->user_id, (int) $tag->last_printed_by);
    }

    /* first_printed_at is evidence of when a label first existed. A reprint
       is a new event, not a correction of that one. */
    public function test_a_reprint_counts_up_but_never_moves_the_first_date(): void
    {
        $tag  = $this->materialTag();
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $firstDate = $tag->refresh()->first_printed_at;

        $this->travel(2)->minutes();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $tag->refresh();

        $this->assertSame(2, (int) $tag->print_count);
        $this->assertEquals($firstDate, $tag->first_printed_at, 'the first send owns that date');
        $this->assertTrue($tag->last_printed_at->greaterThan($firstDate));
    }

    /* The stamp means "the job left for the printer". A job that never left
       must leave no trace, or the queue's "not yet sent" filter starts hiding
       labels nobody ever printed. */
    public function test_a_dead_printer_stamps_nothing(): void
    {
        /* An empty transport is how a location is left with no printer —
           the same state a warehouse nobody has configured yet is in. */
        SystemSetting::setLabelPrinter($this->printerLocation->location_id, ['transport' => '']);
        $tag = $this->materialTag();

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(503);

        $tag->refresh();

        $this->assertSame(0, (int) $tag->print_count);
        $this->assertNull($tag->first_printed_at);
        $this->assertNull($tag->last_printed_at);
    }

    /* A refused tag never reached the builder, so it must not be counted
       among what was sent. */
    public function test_a_refused_tag_is_not_stamped(): void
    {
        $tag = $this->materialTag(['supplier_barcode' => 'SUP-9911']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(422);

        $this->assertSame(0, (int) $tag->refresh()->print_count);
    }

    /* The single-refused-tag case above documents the 422 behaviour, but it
       can't tell "only what was sent got stamped" apart from "nothing was
       stamped because nothing was sent" — the whole request short-circuits
       at printable->isEmpty() either way. The real production shape is a
       mixed bulk selection: the UI's own copy is "38 selected · 6 are
       supplier codes and won't be included in a bulk print". This proves the
       refused tag stays untouched — and never even reaches the printer —
       when the request goes through because something else in it could
       print. */
    public function test_a_refused_tag_in_a_mixed_batch_is_left_untouched(): void
    {
        $printableTag = $this->materialTag();
        $refusedTag   = $this->materialTag(['supplier_barcode' => 'SUP-9911']);
        $user         = $this->user();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$printableTag->serial_id, $refusedTag->serial_id],
        ])->assertOk();

        $printableTag->refresh();
        $refusedTag->refresh();

        $this->assertSame(1, (int) $printableTag->print_count);
        $this->assertNotNull($printableTag->last_printed_at);

        $this->assertSame(0, (int) $refusedTag->print_count);
        $this->assertNull($refusedTag->first_printed_at);
        $this->assertNull($refusedTag->last_printed_at);
        $this->assertNull($refusedTag->last_printed_by);

        $job = file_get_contents($this->spoolFile);
        $this->assertStringContainsString($printableTag->serial_number, $job);
        $this->assertStringNotContainsString($refusedTag->serial_number, $job, 'a refused tag must never reach the printer');
    }
}
