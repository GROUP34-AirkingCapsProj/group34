<?php

namespace Tests\Feature;

use App\Events\InventoryChanged;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    public function test_dispatcher_broadcasts_inventory_event(): void
    {
        Event::fake([InventoryChanged::class]);

        RealtimeBroadcaster::inventoryChanged(4, 'stock_received');

        Event::assertDispatched(InventoryChanged::class, function ($e) {
            return $e->locationId === 4 && $e->type === 'stock_received';
        });
    }

    public function test_null_location_is_a_noop(): void
    {
        Event::fake([InventoryChanged::class]);

        RealtimeBroadcaster::inventoryChanged(null);

        Event::assertNotDispatched(InventoryChanged::class);
    }
}
