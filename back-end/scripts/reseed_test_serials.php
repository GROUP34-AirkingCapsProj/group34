<?php
// Reseed serials and inventory for Phase 4 tests
use App\Models\Product;
use App\Models\ItemSerial;
use App\Models\Inventory;

$STATUS_IN_STOCK = 26;
$locationId = 1;

// --- Appliance products ---
echo "\n=== APPLIANCE PRODUCTS ===\n";
$appliances = Product::with('applianceDetail.brand')
    ->where('product_type', 'appliance')
    ->limit(10)
    ->get();
foreach ($appliances as $p) {
    $ad = $p->applianceDetail;
    echo "  ID={$p->product_id} | {$p->product_name}"
        . " | code={$p->product_code} | price={$p->unit_price}"
        . " | warranty=" . ($ad?->warranty_period_months ?? 0) . "mo\n";
}

// Check existing inventory for appliances
echo "\n=== APPLIANCE INVENTORY (all) ===\n";
$invs = Inventory::with('product')
    ->whereHas('product', fn($q) => $q->where('product_type', 'appliance'))
    ->get();
foreach ($invs as $i) {
    echo "  product_id={$i->product_id} | {$i->product?->product_name} | qty={$i->quantity_on_hand} | loc={$i->location_id}\n";
}

// Seed: add 3 serials for the first appliance product that has inventory
$seeded = [];
foreach ($appliances as $p) {
    $inv = Inventory::where('product_id', $p->product_id)
        ->where('location_id', $locationId)
        ->first();

    if (!$inv) {
        // create inventory row
        $inv = Inventory::create([
            'product_id'         => $p->product_id,
            'location_id'        => $locationId,
            'quantity_on_hand'   => 3,
            'available_quantity' => 3,
            'packages_on_hand'   => 0,
            'reserved_quantity'  => 0,
            'reorder_point'      => 1,
        ]);
        echo "\n  CREATED inventory for product_id={$p->product_id}\n";
    } else {
        $inv->quantity_on_hand   = max($inv->quantity_on_hand, 3);
        $inv->available_quantity = max($inv->available_quantity, 3);
        $inv->save();
        echo "\n  UPDATED inventory for product_id={$p->product_id} -> qty={$inv->quantity_on_hand}\n";
    }

    // Add 3 test serials
    $testSerials = ["TEST-A-001", "TEST-A-002", "TEST-A-003"];
    foreach ($testSerials as $sn) {
        $exists = ItemSerial::where('serial_number', $sn)->exists();
        if (!$exists) {
            ItemSerial::create([
                'product_id'    => $p->product_id,
                'location_id'   => $locationId,
                'serial_number' => $sn,
                'serial_type'   => 'received',
                'status_id'     => $STATUS_IN_STOCK,
                'scanned_by'    => 1,
            ]);
            echo "  SEEDED serial={$sn} for product_id={$p->product_id}\n";
        } else {
            // Update to make sure it's in stock at location 1
            ItemSerial::where('serial_number', $sn)->update([
                'status_id'  => $STATUS_IN_STOCK,
                'location_id' => $locationId,
                'product_id' => $p->product_id,
            ]);
            echo "  UPDATED serial={$sn} status=26 for product_id={$p->product_id}\n";
        }
        $seeded[] = ['serial' => $sn, 'product_id' => $p->product_id, 'product_name' => $p->product_name, 'warranty' => $p->applianceDetail?->warranty_period_months];
    }
    break; // only first appliance
}

// Also ensure copper tube has enough stock
$copperTube = Product::where('product_name', 'like', '%copper%')->first();
if ($copperTube) {
    $inv = Inventory::where('product_id', $copperTube->product_id)
        ->where('location_id', $locationId)->first();
    if ($inv && $inv->quantity_on_hand < 100) {
        $inv->quantity_on_hand   = 100;
        $inv->available_quantity = 100;
        $inv->save();
        echo "\nRestocked copper tube to 100 units\n";
    }
}

echo "\n=== SEEDED SERIALS ===\n";
foreach ($seeded as $s) {
    echo "  serial={$s['serial']} | product={$s['product_name']} | warranty={$s['warranty']}mo\n";
}

echo "\nDone.\n";
