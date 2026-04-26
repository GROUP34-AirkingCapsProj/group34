<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add new columns to locations table (if not already present)
        Schema::table('locations', function (Blueprint $table) {
            if (!Schema::hasColumn('locations', 'code')) {
                $table->string('code', 50)->nullable()->after('location_name');
            }
            if (!Schema::hasColumn('locations', 'contact_number')) {
                $table->string('contact_number', 50)->nullable();
            }
            if (!Schema::hasColumn('locations', 'manager')) {
                $table->string('manager', 255)->nullable();
            }
            if (!Schema::hasColumn('locations', 'capacity')) {
                $table->integer('capacity')->nullable();
            }
            if (!Schema::hasColumn('locations', 'opening_date')) {
                $table->date('opening_date')->nullable();
            }
            if (!Schema::hasColumn('locations', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        // 2. Copy existing branches into locations as location_type = 'branch'
        if (Schema::hasTable('branches')) {
            $branches = DB::table('branches')->get();
            foreach ($branches as $b) {
                // Skip if already migrated (check by code + location_type)
                $exists = DB::table('locations')
                    ->where('code', $b->code)
                    ->where('location_type', 'branch')
                    ->exists();
                if ($exists) continue;

                DB::table('locations')->insert([
                    'location_name'   => $b->name,
                    'code'            => $b->code,
                    'address'         => $b->address,
                    'city'            => $b->city,
                    'province'        => $b->province,
                    'region'          => $b->region,
                    'location_type'   => 'branch',
                    'contact_number'  => $b->contact_number,
                    'capacity'        => $b->capacity,
                    'opening_date'    => $b->opening_date,
                    'is_active'       => $b->is_active ?? true,
                    'status_id'       => ($b->is_active ?? true) ? 1 : 2, // map to Active/Inactive status_lookup
                    'created_at'      => $b->created_at ?? now(),
                    'updated_at'      => $b->updated_at ?? now(),
                ]);
            }
        }

        // 3. Copy existing warehouses into locations as location_type = 'warehouse'
        if (Schema::hasTable('warehouses')) {
            $warehouses = DB::table('warehouses')->get();
            foreach ($warehouses as $w) {
                $exists = DB::table('locations')
                    ->where('code', $w->code)
                    ->where('location_type', 'warehouse')
                    ->exists();
                if ($exists) continue;

                DB::table('locations')->insert([
                    'location_name'   => $w->name,
                    'code'            => $w->code,
                    'address'         => $w->location,
                    'location_type'   => 'warehouse',
                    'contact_number'  => $w->contact_number,
                    'manager'         => $w->manager,
                    'capacity'        => $w->capacity,
                    'opening_date'    => $w->opening_date,
                    'is_active'       => ($w->status === 'Active'),
                    'status_id'       => ($w->status === 'Active') ? 1 : 2,
                    'created_at'      => $w->created_at ?? now(),
                    'updated_at'      => $w->updated_at ?? now(),
                ]);
            }
        }

        // 4. Update users.branch_id references to point to new location_id
        if (Schema::hasTable('branches') && Schema::hasColumn('users', 'branch_id')) {
            $users = DB::table('users')->whereNotNull('branch_id')->get();
            foreach ($users as $u) {
                $location = DB::table('locations')
                    ->where('location_type', 'branch')
                    ->whereRaw('locations.location_id = ?', [$u->branch_id])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM branches WHERE branches.id = ? AND branches.code = locations.code)', [$u->branch_id])
                    ->first();

                if ($location) {
                    DB::table('users')->where('user_id', $u->user_id)->update([
                        'location_id' => $location->location_id,
                    ]);
                }
            }
        }

        // 5. Update inventory.location_id for warehouses
        if (Schema::hasTable('warehouses') && Schema::hasColumn('inventory', 'warehouse_id')) {
            $inventories = DB::table('inventory')->whereNotNull('warehouse_id')->get();
            foreach ($inventories as $inv) {
                $warehouse = DB::table('warehouses')->where('id', $inv->warehouse_id)->first();
                if ($warehouse) {
                    $location = DB::table('locations')
                        ->where('code', $warehouse->code)
                        ->where('location_type', 'warehouse')
                        ->first();
                    if ($location) {
                        DB::table('inventory')->where('inventory_id', $inv->inventory_id)->update([
                            'location_id' => $location->location_id,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['code', 'contact_number', 'manager', 'capacity', 'opening_date', 'is_active']);
        });
    }
};
