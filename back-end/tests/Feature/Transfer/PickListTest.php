<?php

namespace Tests\Feature\Transfer;

class PickListTest extends TransferTestCase
{
    public function test_installation_material_pick_list_is_fifo_by_zone(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeInstallationMaterial();

        $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 3, 'received_at' => '2026-01-01']);
        $this->makeBatch($product, $src, ['zone_label' => 'B-02', 'quantity_remaining' => 5, 'received_at' => '2026-02-01']);

        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 6]]);

        $list = app(\App\Services\TransferService::class)->pickList($ist);
        $line = collect($list)->firstWhere('product_id', $product->product_id);

        $this->assertSame('A-01', $line['suggestions'][0]['zone_label']);
        $this->assertSame(3, $line['suggestions'][0]['quantity']);
        $this->assertSame('B-02', $line['suggestions'][1]['zone_label']);
        $this->assertSame(3, $line['suggestions'][1]['quantity']);
    }

    public function test_appliance_pick_list_lists_serials_oldest_first(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeAppliance();

        $b1 = $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 1, 'received_at' => '2026-01-01']);
        $b2 = $this->makeBatch($product, $src, ['zone_label' => 'B-02', 'quantity_remaining' => 1, 'received_at' => '2026-02-01']);
        $this->makeSerial($product, $src, 'SN-OLD', $b1->receiving_id);
        $this->makeSerial($product, $src, 'SN-NEW', $b2->receiving_id);

        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 2]]);

        $list = app(\App\Services\TransferService::class)->pickList($ist);
        $line = collect($list)->firstWhere('product_id', $product->product_id);

        $this->assertSame('SN-OLD', $line['suggestions'][0]['serial_number']);
        $this->assertSame('A-01', $line['suggestions'][0]['zone_label']);
        $this->assertSame('SN-NEW', $line['suggestions'][1]['serial_number']);
    }
}
