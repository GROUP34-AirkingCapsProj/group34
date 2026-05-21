<?php
use App\Models\ItemSerial;
use App\Models\Inventory;

// Ensure inventory has stock
$inv = Inventory::where('product_id', 1)->where('location_id', 1)->first();
if ($inv) {
    $inv->quantity_on_hand = max($inv->quantity_on_hand, 5);
    $inv->available_quantity = max($inv->available_quantity, 5);
    $inv->save();
}

ItemSerial::updateOrCreate(
    ['serial_number' => 'TEST-B-001'],
    [
        'product_id'    => 1,
        'location_id'   => 1,
        'serial_type'   => 'received',
        'status_id'     => 26,
        'scanned_by'    => 2,
    ]
);
echo "Seeded TEST-B-001, inventory qty={$inv->quantity_on_hand}\n";
