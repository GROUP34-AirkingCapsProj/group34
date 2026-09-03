<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The domain rows the test suite reads rather than creates.
 *
 * A large family of tests works against whatever is already in the database:
 * `User::query()->firstOrFail()` for an actor, `Product::where('product_type',
 * 'appliance')->first()` for something to put in stock, `DB::table('users')
 * ->value('user_id')` for a foreign key. On a developer's machine that always
 * resolves, because their test database is a copy of a working install. On CI
 * it resolved to null: database/ci-schema.sql is deliberately schema plus
 * lookups only — no users, customers or suppliers — so 162 tests died on a
 * ModelNotFoundException, a `Failed asserting that null is not null`, or an
 * `INSERT ... reported_by = 0` that the users foreign key refused.
 *
 * This seeder supplies exactly that baseline and nothing more: one admin to act
 * as, and one product of each type. Everything else a test needs it still
 * builds itself.
 *
 * Idempotent, and additive only — it inserts on a natural key that no other
 * seeder or test uses, so running it against a populated database (a
 * developer's airkingV5_test, say) changes nothing that is already there.
 *
 * The admin is deliberately the FIRST row inserted into an empty users table:
 * the tests above take the lowest user_id, and an actor without admin rights
 * would fail the permission gates they are not testing.
 */
class TestBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedProducts();
    }

    /**
     * must_change_password defaults to 1 in the schema, and the gate middleware
     * turns any protected route into a 403 for a user carrying that flag. A
     * seeded actor has no password to change, so it is cleared here rather than
     * left to trip every request the tests make.
     */
    private function seedAdmin(): void
    {
        $roleId = (int) DB::table('roles')->where('role_name', 'admin')->value('role_id');
        $statusId = (int) DB::table('status_lookup')
            ->where('status_category', 'user')->where('status_name', 'Active')->value('status_id');

        if (!$roleId || !$statusId) {
            $this->command?->error('TestBaselineSeeder: roles / status_lookup are empty. Import the schema dump first.');
            return;
        }

        DB::table('users')->updateOrInsert(
            ['email' => 'test.admin@airking.test'],
            [
                'first_name'           => 'Test',
                'last_name'            => 'Admin',
                'username'             => 'test.admin',
                // Every reset path mails the recovery address, so an account
                // without one cannot be driven through those flows at all.
                'recovery_email'       => 'test.admin.recovery@airking.test',
                'role_id'              => $roleId,
                // Centralized, so location scope never narrows what the actor
                // can see. A scoped actor would make half these tests read as
                // empty rather than as wrong.
                'location_id'          => null,
                'status_id'            => $statusId,
                // Random, and never recorded anywhere: the tests reach this
                // account through actingAs(), so no caller needs the password,
                // and a fixed one committed to a public repo would be an admin
                // login waiting for whoever runs this seeder off-target.
                'password_hash'        => Hash::make(Str::random(48)),
                'must_change_password' => 0,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        );
    }

    /**
     * One of each product_type. The enum holds exactly `appliance` and
     * `installation_material`; the warranty and incident suites ask for both by
     * name, and get an assertion failure rather than a skip when either is
     * missing.
     */
    private function seedProducts(): void
    {
        $activeProduct = DB::table('status_lookup')
            ->where('status_category', 'product')->where('status_name', 'Active')->value('status_id');

        foreach ([
            ['TEST-APPLIANCE-001', 'Test Appliance', 'appliance', 15000.00, 12000.00],
            ['TEST-MATERIAL-001', 'Test Installation Material', 'installation_material', 250.00, 150.00],
        ] as [$code, $name, $type, $price, $cost]) {
            DB::table('products')->updateOrInsert(
                ['product_code' => $code],
                [
                    'product_name'       => $name,
                    'product_type'       => $type,
                    'unit_price'         => $price,
                    'cost_price'         => $cost,
                    'recommended_stocks' => 10,
                    'status_id'          => $activeProduct,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
            );
        }
    }
}
