<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ProcurementTestCase extends TestCase
{
    use DatabaseTransactions;

    protected int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Procurement Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    protected function makeUser(string $roleSlug): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    protected function makePr(float $estimatedTotal, int $statusId = 13, array $overrides = []): PurchaseRequest
    {
        return PurchaseRequest::create(array_merge([
            'location_id'     => $this->locationId,
            'pr_number'       => 'PR-TEST-' . strtoupper(Str::random(6)),
            'request_date'    => today(),
            'needed_by_date'  => today()->addDays(7),
            'estimated_total' => $estimatedTotal,
            'status_id'       => $statusId,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ], $overrides));
    }

    protected function makePo(PurchaseRequest $pr, float $totalAmount, int $statusId = 7): PurchaseOrder
    {
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Procurement Test Supplier ' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'supplier_id');

        return PurchaseOrder::create([
            'pr_id'        => $pr->pr_id,
            'supplier_id'  => $supplierId,
            'location_id'  => $pr->location_id,
            'po_number'    => 'PO-TEST-' . strtoupper(Str::random(6)),
            'order_date'   => today(),
            'total_amount' => $totalAmount,
            'grand_total'  => $totalAmount,
            'status_id'    => $statusId,
            'created_by'   => $pr->requested_by,
        ]);
    }
}
