<?php

namespace Tests\Feature\Warranty;

use App\Models\ApplianceDetail;
use App\Models\Customer;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Issuance;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One serial in, a definite answer out.
 *
 * The lookup used to search warranty_records alone, so every unit still in the
 * warehouse came back 404 — including a unit sitting on the shelf under an open
 * incident with supplier cover running for another three years. "Not found" was
 * both true and useless.
 */
class SerialLookupTest extends TestCase
{
    use DatabaseTransactions;

    private const WARRANTY_ACTIVE = 33;

    /**
     * Resolved by NAME, never hardcoded.
     *
     * Inventory status ids differ between the dev and test databases —
     * Quarantined is 54 in dev and 53 in test — which is why every service in
     * this codebase looks them up this way.
     */
    private function inventoryStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');
    }

    private function admin(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username'      => 'lookup_' . uniqid(),
            'first_name'    => 'Look',
            'last_name'     => 'Up',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    private function appliance(int $months = 36): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Lookup Appliance',
            'product_type' => 'appliance',
        ]);

        ApplianceDetail::create([
            'product_id'             => $product->product_id,
            'model_code'             => 'MDL-' . uniqid(),
            'warranty_period_months' => $months,
            'is_serialized'          => true,
        ]);

        return $product;
    }

    /** A receiving from a real supplier, reached through the PO as live data is. */
    private function receiving(Location $location, int $monthsAgo = 2): Receiving
    {
        $supplier = Supplier::create(['supplier_name' => 'Sup-' . uniqid(), 'origin' => 'Local']);

        $po = PurchaseOrder::create([
            'po_number'   => 'PO-' . uniqid(),
            'location_id' => $location->location_id,
            'supplier_id' => $supplier->supplier_id,
            'order_date'  => Carbon::today()->subMonths($monthsAgo)->toDateString(),
            'status_id'   => 1,
        ]);

        return Receiving::create([
            'po_id'            => $po->po_id,
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => Carbon::today()->subMonths($monthsAgo)->toDateString(),
        ]);
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Lookup Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function lookup(string $serial)
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/warranties/lookup/' . urlencode($serial));
    }

    /** A unit on the shelf: found, placed, and covered by its supplier. */
    public function test_a_unit_in_stock_is_reported_as_in_stock_with_supplier_cover(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $rcv      = $this->receiving($location, monthsAgo: 2);

        $serial = 'SN-STOCK-' . strtoupper(uniqid());
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $serial,
            'serial_type'   => 'received',
            'status_id'     => $this->inventoryStatusId('In Stock'),
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'in_stock')
            ->assertJsonPath('unit.status_name', 'In Stock')
            ->assertJsonPath('unit.location_name', $location->location_name)
            ->assertJsonPath('supplier_warranty.state', 'in_warranty')
            ->assertJsonPath('customer_warranty.state', 'none');
    }

    /** The 3333 case: physically here, held by a report, and it must say so. */
    public function test_a_quarantined_unit_still_reports_where_it_is(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $rcv      = $this->receiving($location, monthsAgo: 1);

        $serial = 'SN-QUAR-' . strtoupper(uniqid());
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $serial,
            'serial_type'   => 'received',
            'status_id'     => $this->inventoryStatusId('Quarantined'),
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'in_stock')
            ->assertJsonPath('unit.status_name', 'Quarantined');
    }

    /** Sold: no serial row left, but the customer and their cover are known. */
    public function test_a_sold_unit_reports_its_customer_and_warranty(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $customer = Customer::create(['customer_name' => 'Buyer One', 'customer_type' => 'individual']);

        $serial = 'SN-SOLD-' . strtoupper(uniqid());

        WarrantyRecord::create([
            'product_id'             => $product->product_id,
            'customer_id'            => $customer->customer_id,
            'serial_number'          => $serial,
            'sale_date'              => now()->subMonths(2)->toDateString(),
            'warranty_start_date'    => now()->subMonths(2)->toDateString(),
            'warranty_end_date'      => now()->addMonths(34)->toDateString(),
            'warranty_period_months' => 36,
            'status_id'              => self::WARRANTY_ACTIVE,
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'sold')
            ->assertJsonPath('sale.customer_name', 'Buyer One')
            ->assertJsonPath('customer_warranty.state', 'in_warranty');
    }

    /** Sold long ago: the customer's cover has run out and the panel says so. */
    public function test_a_sold_unit_past_its_cover_reports_expired(): void
    {
        $product  = $this->appliance(12);
        $customer = Customer::create(['customer_name' => 'Buyer Two', 'customer_type' => 'individual']);

        $serial = 'SN-LAPSED-' . strtoupper(uniqid());

        WarrantyRecord::create([
            'product_id'             => $product->product_id,
            'customer_id'            => $customer->customer_id,
            'serial_number'          => $serial,
            'sale_date'              => now()->subYears(3)->toDateString(),
            'warranty_start_date'    => now()->subYears(3)->toDateString(),
            'warranty_end_date'      => now()->subYears(2)->toDateString(),
            'warranty_period_months' => 12,
            'status_id'              => self::WARRANTY_ACTIVE,
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'sold')
            ->assertJsonPath('customer_warranty.state', 'expired');
    }

    /**
     * Sold with no warranty on record — the missing-serial gap, surfaced.
     *
     * The sale exists in issuance_details and nowhere else. Saying "unknown"
     * would hide a real problem; the panel names it instead.
     */
    public function test_a_sale_with_no_warranty_record_is_named_as_such(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $customer = Customer::create(['customer_name' => 'Buyer Three', 'customer_type' => 'individual']);
        $user     = $this->admin();

        $serial = 'SN-NOWAR-' . strtoupper(uniqid());

        $issuance = Issuance::create([
            'location_id'   => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->subDays(5)->toDateString(), 'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal'      => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by'     => $user->user_id, 'status_id' => 31,
        ]);

        $issuance->details()->create([
            'product_id' => $product->product_id, 'serial_number' => $serial,
            'quantity_requested' => 1, 'quantity_issued' => 1, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'sold_no_warranty')
            ->assertJsonPath('sale.customer_name', 'Buyer Three');
    }

    /**
     * Sold and brought back.
     *
     * A returned unit gets no serial row back today, so without this the panel
     * would place a unit standing in the warehouse with a customer.
     */
    public function test_a_returned_unit_outranks_its_sale(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $customer = Customer::create(['customer_name' => 'Buyer Four', 'customer_type' => 'individual']);
        $user     = $this->admin();

        $serial = 'SN-BACK-' . strtoupper(uniqid());

        WarrantyRecord::create([
            'product_id'             => $product->product_id,
            'customer_id'            => $customer->customer_id,
            'serial_number'          => $serial,
            'sale_date'              => now()->subMonths(3)->toDateString(),
            'warranty_start_date'    => now()->subMonths(3)->toDateString(),
            'warranty_end_date'      => now()->addMonths(33)->toDateString(),
            'warranty_period_months' => 36,
            'status_id'              => self::WARRANTY_ACTIVE,
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-LOOKUP-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'reason'          => IncidentReport::REASON_RETURN,
            'return_counterparty' => 'customer',
            'quantity'        => 1,
            'narrative'       => 'customer brought it back',
            'reported_by'     => $user->getKey(),
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        IncidentItem::create([
            'incident_id'   => $incident->incident_id,
            'product_id'    => $product->product_id,
            'serial_number' => $serial,
            'is_inbound'    => true,
            'quantity'      => 1,
            'status'        => IncidentItem::STATUS_QUARANTINED,
        ]);

        $this->lookup($serial)
            ->assertOk()
            ->assertJsonPath('state', 'returned')
            ->assertJsonPath('incident.incident_number', $incident->incident_number)
            ->assertJsonPath('sale.customer_name', 'Buyer Four');
    }

    /** Unknown is an answer, not an error. */
    public function test_an_unknown_serial_answers_rather_than_failing(): void
    {
        $this->lookup('SN-NOWHERE-' . strtoupper(uniqid()))
            ->assertOk()
            ->assertJsonPath('state', 'unknown');
    }

    /** Formatting differences must not decide whether a unit exists. */
    public function test_a_differently_formatted_serial_still_matches(): void
    {
        $location = $this->location();
        $product  = $this->appliance(36);
        $rcv      = $this->receiving($location);

        $serial = 'SN-FMT-' . strtoupper(uniqid());
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $serial,
            'serial_type'   => 'received',
            'status_id'     => $this->inventoryStatusId('In Stock'),
        ]);

        $this->lookup(strtolower(str_replace('-', ' ', $serial)))
            ->assertOk()
            ->assertJsonPath('state', 'in_stock');
    }
}
