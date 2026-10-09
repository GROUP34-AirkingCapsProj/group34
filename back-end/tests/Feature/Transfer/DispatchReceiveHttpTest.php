<?php

namespace Tests\Feature\Transfer;

class DispatchReceiveHttpTest extends TransferTestCase
{
    public function test_full_dispatch_then_receive_via_transfers_endpoints(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\TransferChanged::class]);
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeAppliance();
        $serials = $this->makeSerials($src->location_id, $product->product_id, 2);
        $this->setStock($src->location_id, $product->product_id, 2);
        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 2]]);

        $srcUser = $this->makeUserAt($src, 'warehouse_personnel');
        foreach ($serials as $sn) {
            $this->actingAs($srcUser, 'sanctum')
                ->postJson("/api/transfers/{$ist->transfer_id}/scan", ['barcode' => $sn])
                ->assertOk();
        }
        $this->actingAs($srcUser, 'sanctum')
            ->postJson("/api/transfers/{$ist->transfer_id}/confirm-dispatch")
            ->assertOk();
        $this->assertSame($this->statusId('In Transit'), (int) $ist->fresh()->status_id);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\TransferChanged::class,
            fn ($event) => $event->type === 'dispatched' && $event->from === (int) $src->location_id && $event->to === (int) $dst->location_id);

        $dstUser = $this->makeUserAt($dst, 'warehouse_personnel');
        foreach ($serials as $sn) {
            $this->actingAs($dstUser, 'sanctum')
                ->postJson("/api/transfers/{$ist->transfer_id}/receive-scan", ['barcode' => $sn])
                ->assertOk();
        }
        $this->actingAs($dstUser, 'sanctum')
            ->postJson("/api/transfers/{$ist->transfer_id}/receive-confirm")
            ->assertOk();
        $this->assertSame($this->statusId('Received'), (int) $ist->fresh()->status_id);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\TransferChanged::class,
            fn ($event) => $event->type === 'received');
    }
}
