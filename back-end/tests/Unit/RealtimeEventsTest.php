<?php

namespace Tests\Unit;

use App\Events\ApprovalQueueChanged;
use App\Events\InventoryChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\TransferChanged;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\TestCase;

class RealtimeEventsTest extends TestCase
{
    private function names(array $channels): array
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $channels);
    }

    public function test_approval_queue_event_channels_and_name(): void
    {
        $e = new ApprovalQueueChanged(3, 'po_submitted');
        $this->assertSame('approval-queue.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.3'],
            $this->names($e->broadcastOn())
        );
        $this->assertSame(3, $e->broadcastWith()['location_id']);
        $this->assertSame('po_submitted', $e->broadcastWith()['type']);
    }

    public function test_inventory_event_channels(): void
    {
        $e = new InventoryChanged(5, 'stock_received');
        $this->assertSame('inventory.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.5'],
            $this->names($e->broadcastOn())
        );
    }

    public function test_purchase_order_event_channels(): void
    {
        $e = new PurchaseOrderChanged(5, 'po_authorized');
        $this->assertSame('purchase-order.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.5'],
            $this->names($e->broadcastOn())
        );
    }

    public function test_transfer_event_broadcasts_to_both_locations(): void
    {
        $e = new TransferChanged(2, 9, 'ist_dispatched');
        $this->assertSame('transfer.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.2', 'private-location.9'],
            $this->names($e->broadcastOn())
        );
        $this->assertSame(2, $e->broadcastWith()['from']);
        $this->assertSame(9, $e->broadcastWith()['to']);
    }
}
