<?php

namespace Tests\Feature\History;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReceivingReceiptTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'System Administrator']);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::where('role_name', 'admin')->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;
        return User::forceCreate([
            'first_name' => 'Rcpt', 'last_name' => 'Admin',
            'email' => 'rcpt_' . uniqid() . '@test.local',
            'username' => 'rcpt_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->role_id, 'status_id' => $statusId,
        ]);
    }

    private function makeReceiving(User $u): Receiving
    {
        $loc = Location::forceCreate([
            'location_code' => 'RWH-' . uniqid(), 'location_name' => 'Rcpt WH',
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
        $sup = Supplier::forceCreate(['supplier_name' => 'Rcpt Supplier ' . uniqid()]);
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Test Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-R-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        $r = Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'GRN-' . uniqid(),
            'receiving_date' => today()->toDateString(), 'received_by' => $u->user_id,
            'total_quantity_received' => 3,
        ]);
        ReceivingDetail::create([
            'receiving_id' => $r->receiving_id, 'product_id' => $prod->product_id,
            'quantity_amount' => 3, 'prod_price' => 10, 'condition' => 'Good',
        ]);
        return $r;
    }

    /**
     * The download routes carry two middleware: `permission` (which resolves the
     * user — from the SPA's Sanctum session cookie in production) and
     * `auth.query.token` (which validates a real token from the ?token= param, so a
     * browser download without an Authorization header still authenticates). A real
     * browser request satisfies both, so the test does too: authenticate the session
     * AND pass a real personal-access token.
     */
    private function tokenFor(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    public function test_receipt_pdf_downloads(): void
    {
        $u = $this->adminUser();
        $r = $this->makeReceiving($u);

        $res = $this->actingAs($u, 'sanctum')
            ->get("/api/receivings/{$r->receiving_id}/receipt/pdf?token=" . $this->tokenFor($u));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_receipt_csv_downloads(): void
    {
        $u = $this->adminUser();
        $r = $this->makeReceiving($u);

        $res = $this->actingAs($u, 'sanctum')
            ->get("/api/receivings/{$r->receiving_id}/receipt/csv?token=" . $this->tokenFor($u));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
    }

    public function test_receipt_pdf_unknown_id_404(): void
    {
        $u = $this->adminUser();
        $this->actingAs($u, 'sanctum')
            ->get('/api/receivings/999999/receipt/pdf?token=' . $this->tokenFor($u))
            ->assertNotFound();
    }

    public function test_consolidated_po_receipt_pdf_downloads(): void
    {
        $u = $this->adminUser();
        $r = $this->makeReceiving($u);
        $poId = $r->po_id;

        $res = $this->actingAs($u, 'sanctum')
            ->get("/api/purchase-orders/{$poId}/receiving-receipt/pdf?token=" . $this->tokenFor($u));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    /**
     * Browser scenario: a top-level navigation carries NO session/Authorization,
     * only ?token=. This must still return the PDF — AuthenticateFromQueryToken
     * has to resolve the user before CheckPermission runs.
     */
    public function test_consolidated_po_receipt_pdf_downloads_with_query_token_only(): void
    {
        $u = $this->adminUser();
        $r = $this->makeReceiving($u);

        $res = $this->get("/api/purchase-orders/{$r->po_id}/receiving-receipt/pdf?token=" . $this->tokenFor($u));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_consolidated_po_receipt_404_when_nothing_received(): void
    {
        $u = $this->adminUser();
        // A PO with no receivings at all.
        $loc = Location::forceCreate([
            'location_code' => 'NRWH-' . uniqid(), 'location_name' => 'No-Recv WH',
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
        $sup = Supplier::forceCreate(['supplier_name' => 'NR Supplier ' . uniqid()]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-NR-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 0, 'grand_total' => 0,
            'created_by' => $u->user_id,
        ]);

        $this->actingAs($u, 'sanctum')
            ->get("/api/purchase-orders/{$po->po_id}/receiving-receipt/pdf?token=" . $this->tokenFor($u))
            ->assertNotFound();
    }
}
