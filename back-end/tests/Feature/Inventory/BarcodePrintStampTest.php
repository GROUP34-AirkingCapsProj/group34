<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
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
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L',
            'location_type' => 'warehouse',
        ]);

        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
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
}
