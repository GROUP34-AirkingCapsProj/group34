<?php

namespace Tests\Feature;

use App\Events\ApprovalQueueChanged;
use App\Events\PurchaseOrderChanged;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTriggersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_order_authorize_broadcasts(): void
    {
        Event::fake([PurchaseOrderChanged::class, ApprovalQueueChanged::class]);

        $admin = User::query()->where('role_id', 1)->first();
        $po = PurchaseOrder::query()->where('status_id', 7)->first(); // Pending
        if (!$admin || !$po) {
            $this->markTestSkipped('Seeded admin + pending PO required.');
        }

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])
            ->assertSuccessful();

        Event::assertDispatched(PurchaseOrderChanged::class);
    }
}
