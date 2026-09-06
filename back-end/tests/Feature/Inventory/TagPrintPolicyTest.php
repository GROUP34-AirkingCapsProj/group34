<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Services\TagPrintPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Which identity a tag carries, and who is allowed to reprint it.
 *
 * The three tiers are not cosmetic. A minted tag is ours and may be run off
 * by the roll. A supplier code and a manufacturer serial belong to someone
 * else: reprinting one is legitimate when a sticker is damaged, but a bulk
 * run would paper a whole delivery in duplicate labels, so bulk refuses them.
 */
class TagPrintPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private TagPrintPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TagPrintPolicy();
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L',
            'location_type' => 'warehouse',
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

        $serial = ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));

        return $serial->load('product.consumableDetail');
    }

    private function applianceSerial(): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        $serial = ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'MFG-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        return $serial->load('product.applianceDetail');
    }

    public function test_it_names_each_tier(): void
    {
        $this->assertSame('minted', $this->policy->tier($this->materialTag()));
        $this->assertSame('supplier', $this->policy->tier($this->materialTag(['supplier_barcode' => 'SUP-9911'])));
        $this->assertSame('manufacturer', $this->policy->tier($this->applianceSerial()));
    }

    /**
     * A serialized appliance also has a NULL supplier_barcode, because
     * ReceivingPostingService::mintTags never sets one. Checking the barcode
     * before the product would therefore read an appliance serial as one of
     * our own tags and let a bulk run reprint it.
     */
    public function test_serialized_wins_over_a_null_supplier_barcode(): void
    {
        $serial = $this->applianceSerial();

        $this->assertNull($serial->supplier_barcode);
        $this->assertSame('manufacturer', $this->policy->tier($serial));
    }

    public function test_bulk_prints_only_what_we_minted(): void
    {
        $this->assertTrue($this->policy->printable($this->materialTag(), 'bulk'));
        $this->assertFalse($this->policy->printable($this->materialTag(['supplier_barcode' => 'SUP-9911']), 'bulk'));
        $this->assertFalse($this->policy->printable($this->applianceSerial(), 'bulk'));
    }

    public function test_a_single_reprint_accepts_every_tier(): void
    {
        $this->assertTrue($this->policy->printable($this->materialTag(), 'single'));
        $this->assertTrue($this->policy->printable($this->materialTag(['supplier_barcode' => 'SUP-9911']), 'single'));
        $this->assertTrue($this->policy->printable($this->applianceSerial(), 'single'));
    }

    /* A retired tag points at stock that is gone. Labelling it is wrong no
       matter who is asking or how few they asked for. */
    public function test_no_mode_reprints_a_retired_tag(): void
    {
        $retired = $this->materialTag(['serial_type' => 'issued']);

        $this->assertFalse($this->policy->printable($retired, 'bulk'));
        $this->assertFalse($this->policy->printable($retired, 'single'));
    }

    public function test_a_tag_with_no_product_is_never_printable(): void
    {
        $orphan = $this->materialTag();
        $orphan->setRelation('product', null);

        $this->assertFalse($this->policy->printable($orphan, 'single'));
    }
}
