<?php

namespace Tests\Feature\Scoping;

use App\Models\DeliveryReceipt;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Both /serials and /delivery-receipts return rows that carry a location_id,
 * and neither read route is role-gated (see routes/api.php — only the writes
 * carry a `permission:` middleware). A location-scoped user therefore reaches
 * them directly, so they must be filtered the way every other location-bearing
 * list is: via ScopesToUserLocation::forUser().
 */
class SerialAndDeliveryReceiptScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(string $type = 'warehouse'): Location
    {
        return Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
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

    protected function aProduct(): Product
    {
        $p = Product::query()->first();
        $this->assertNotNull($p, 'Test DB must contain at least one product.');
        return $p;
    }

    protected function serialAt(int $locationId): ItemSerial
    {
        return ItemSerial::create([
            'product_id'    => $this->aProduct()->product_id,
            'location_id'   => $locationId,
            'serial_number' => 'SN-' . uniqid(),
        ]);
    }

    protected function drAt(int $locationId): DeliveryReceipt
    {
        return DeliveryReceipt::create([
            'dr_number'    => 'DR-' . uniqid(),
            'location_id'  => $locationId,
            'total_amount' => 0,
        ]);
    }

    public function test_scoped_user_sees_only_their_own_location_serials(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $ours   = $this->serialAt($mine->location_id);
        $other  = $this->serialAt($theirs->location_id);

        $bm   = $this->userAt(3, $mine->location_id);
        $data = $this->actingAs($bm)->getJson('/api/serials')->assertStatus(200)->json('data');
        $ids  = collect($data)->pluck('serial_id')->all();

        $this->assertContains($ours->serial_id, $ids);
        $this->assertNotContains($other->serial_id, $ids, 'A scoped user must not see another location\'s serials.');
    }

    public function test_scoped_user_cannot_fetch_another_locations_serial(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $other  = $this->serialAt($theirs->location_id);

        $bm = $this->userAt(3, $mine->location_id);
        $this->actingAs($bm)->getJson('/api/serials/' . $other->serial_id)->assertStatus(404);
    }

    public function test_centralized_user_still_sees_every_location_serial(): void
    {
        $a = $this->loc();
        $b = $this->loc();
        $sa = $this->serialAt($a->location_id);
        $sb = $this->serialAt($b->location_id);

        // Auditor (role 5) is centralized — must keep seeing everything.
        $auditor = $this->userAt(5, $a->location_id);
        $data = $this->actingAs($auditor)->getJson('/api/serials?per_page=200')->assertStatus(200)->json('data');
        $ids  = collect($data)->pluck('serial_id')->all();

        $this->assertContains($sa->serial_id, $ids);
        $this->assertContains($sb->serial_id, $ids);
    }

    public function test_scoped_user_sees_only_their_own_location_delivery_receipts(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $ours   = $this->drAt($mine->location_id);
        $other  = $this->drAt($theirs->location_id);

        $bm   = $this->userAt(3, $mine->location_id);
        $data = $this->actingAs($bm)->getJson('/api/delivery-receipts')->assertStatus(200)->json('data');
        $ids  = collect($data)->pluck('dr_id')->all();

        $this->assertContains($ours->dr_id, $ids);
        $this->assertNotContains($other->dr_id, $ids, 'A scoped user must not see another location\'s delivery receipts.');
    }

    public function test_scoped_user_cannot_fetch_another_locations_delivery_receipt(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $other  = $this->drAt($theirs->location_id);

        $bm = $this->userAt(3, $mine->location_id);
        $this->actingAs($bm)->getJson('/api/delivery-receipts/' . $other->dr_id)->assertStatus(404);
    }

    public function test_centralized_user_still_sees_every_location_delivery_receipt(): void
    {
        $a  = $this->loc();
        $b  = $this->loc();
        $da = $this->drAt($a->location_id);
        $db = $this->drAt($b->location_id);

        $analyst = $this->userAt(2, $a->location_id);
        $data = $this->actingAs($analyst)->getJson('/api/delivery-receipts?per_page=200')->assertStatus(200)->json('data');
        $ids  = collect($data)->pluck('dr_id')->all();

        $this->assertContains($da->dr_id, $ids);
        $this->assertContains($db->dr_id, $ids);
    }
}
