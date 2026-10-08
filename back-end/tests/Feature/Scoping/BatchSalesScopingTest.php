<?php

namespace Tests\Feature\Scoping;

use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /api/batch carries no permission middleware, so every authenticated user
 * reaches it. Its `inventory` part already calls forUser(), and its
 * `transactions` part filters on the user's location — but the `sales` part
 * queried Sale unscoped, handing a branch manager every branch's sales.
 *
 * Sale carries ScopesToUserLocation and sales.location_id exists, so this part
 * must be filtered the same way its neighbours are.
 */
class BatchSalesScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(): Location
    {
        return Location::create([
            'location_code' => 'BS-' . uniqid(),
            'location_name' => 'Batch Loc ' . uniqid(),
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

    protected function saleAt(int $locationId): Sale
    {
        return Sale::create([
            'invoice_number' => 'INV-' . uniqid(),
            'location_id'    => $locationId,
            'sale_date'      => now()->toDateString(),
            'total_amount'   => 100,
        ]);
    }

    /**
     * The endpoint hardcodes paginate(200) with no ordering, so a freshly
     * created row is not reliably on page 1 of a populated database. These
     * assertions are therefore made over the *composition* of whatever page is
     * returned, which is both pagination-independent and the stronger claim.
     */
    public function test_scoped_user_sees_only_their_location_sales_in_the_batch_payload(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $this->saleAt($mine->location_id);
        $this->saleAt($theirs->location_id);

        $bm   = $this->userAt(3, $mine->location_id);
        $rows = $this->actingAs($bm)
            ->getJson('/api/batch?include=sales')
            ->assertStatus(200)
            ->json('data.sales.data');

        $this->assertNotEmpty($rows, 'Expected the sales part to return rows.');

        $foreign = collect($rows)
            ->pluck('location_id')
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) $mine->location_id)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $foreign,
            '/api/batch?include=sales leaked sales from location(s) ' . implode(',', $foreign) . ' to a scoped user.'
        );
    }

    public function test_centralized_user_still_sees_every_location_sale_in_the_batch_payload(): void
    {
        $a = $this->loc();
        $b = $this->loc();
        $this->saleAt($a->location_id);
        $this->saleAt($b->location_id);

        $admin = $this->userAt(1, $a->location_id);
        $rows  = $this->actingAs($admin)
            ->getJson('/api/batch?include=sales')
            ->assertStatus(200)
            ->json('data.sales.data');

        $locations = collect($rows)->pluck('location_id')->unique()->filter()->values();

        $this->assertGreaterThan(
            1,
            $locations->count(),
            'A centralized role must keep seeing sales across locations.'
        );
    }
}
