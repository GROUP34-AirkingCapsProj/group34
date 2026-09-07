<?php

namespace Tests\Feature\Scoping;

use App\Models\Customer;
use App\Models\Issuance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Customers are deliberately global — they carry no location column and every
 * user must see all of them (ReferenceUnscopedTest). The per-customer COUNTS
 * hanging off them are not global, though: issuances and warranty claims both
 * carry a location_id.
 *
 * The customer list renders issuances_count as "Transactions", so an unscoped
 * count told a branch manager how much business a customer did at every other
 * branch.
 */
class CustomerCountsScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(): Location
    {
        return Location::create([
            'location_code' => 'CC-' . uniqid(),
            'location_name' => 'Cust Loc ' . uniqid(),
            'location_type' => 'warehouse',
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    private function issuanceAt(int $locationId, int $customerId): void
    {
        Issuance::create([
            'customer_id'    => $customerId,
            'location_id'    => $locationId,
            'issuance_date'  => now()->toDateString(),
        ]);
    }

    /** One customer: 1 transaction at my location, 3 at another. */
    private function seedCustomer(): array
    {
        $mine   = $this->loc();
        $theirs = $this->loc();

        $customer = Customer::create([
            'customer_code' => 'C-' . uniqid(),
            'customer_name' => 'Scope Customer ' . uniqid(),
        ]);

        $this->issuanceAt($mine->location_id, $customer->customer_id);
        $this->issuanceAt($theirs->location_id, $customer->customer_id);
        $this->issuanceAt($theirs->location_id, $customer->customer_id);
        $this->issuanceAt($theirs->location_id, $customer->customer_id);

        return [$mine, $customer];
    }

    private function countFor(User $as, Customer $customer): int
    {
        $rows = $this->actingAs($as)
            ->getJson('/api/customers?search=' . urlencode($customer->customer_name))
            ->assertStatus(200)
            ->json('data');

        $row = collect($rows)->firstWhere('customer_id', $customer->customer_id);
        $this->assertNotNull($row, 'Expected the customer in /api/customers.');

        return (int) ($row['issuances_count'] ?? -1);
    }

    public function test_transaction_count_covers_only_the_users_location(): void
    {
        [$mine, $customer] = $this->seedCustomer();
        $bm = $this->userAt(3, $mine->location_id);

        $this->assertSame(
            1,
            $this->countFor($bm, $customer),
            'The customer transaction count included other locations\' business.'
        );
    }

    public function test_centralized_user_still_sees_the_full_transaction_count(): void
    {
        [$mine, $customer] = $this->seedCustomer();
        $admin = $this->userAt(1, $mine->location_id);

        $this->assertSame(4, $this->countFor($admin, $customer));
    }
}
