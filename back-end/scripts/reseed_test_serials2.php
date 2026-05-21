<?php
// Check user IDs and item_serial schema
use App\Models\User;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\Inventory;

$users = User::limit(5)->get(['user_id','first_name','last_name']);
echo "=== USERS ===\n";
foreach ($users as $u) {
    echo "  user_id={$u->user_id} | {$u->first_name} {$u->last_name}\n";
}

// Check item_serial fillable / columns via schema dump
$cols = \Schema::getColumnListing('item_serial');
echo "\n=== item_serial COLUMNS ===\n";
foreach ($cols as $col) {
    echo "  {$col}\n";
}

// Now try seeding with correct user_id
$firstUser = User::first();
$userId = $firstUser?->user_id ?? null;
echo "\nUsing user_id={$userId} for seeding\n";

$STATUS_IN_STOCK = 26;
$locationId = 1;

$product = Product::where('product_type', 'appliance')->first();
echo "Product: {$product->product_id} | {$product->product_name} | warranty={$product->applianceDetail?->warranty_period_months}mo\n";

$testSerials = ["TEST-A-001", "TEST-A-002", "TEST-A-003"];
foreach ($testSerials as $sn) {
    try {
        ItemSerial::updateOrCreate(
            ['serial_number' => $sn],
            [
                'product_id'    => $product->product_id,
                'location_id'   => $locationId,
                'serial_type'   => 'received',
                'status_id'     => $STATUS_IN_STOCK,
                'scanned_by'    => $userId,
            ]
        );
        echo "  OK serial={$sn}\n";
    } catch (\Throwable $e) {
        echo "  FAIL serial={$sn}: " . $e->getMessage() . "\n";
    }
}

// Also reseed copper tube
$copperTube = Product::where('product_name', 'like', '%copper%')->first();
if ($copperTube) {
    $inv = Inventory::where('product_id', $copperTube->product_id)
        ->where('location_id', $locationId)->first();
    if ($inv) {
        $inv->quantity_on_hand   = 100;
        $inv->available_quantity = 100;
        $inv->save();
        echo "\nCopper tube restocked to 100\n";
    }
}

// Verify
echo "\n=== VERIFY SERIALS IN STOCK ===\n";
$serials = ItemSerial::where('status_id', 26)->with('product')->get();
foreach ($serials as $s) {
    echo "  serial={$s->serial_number} | product={$s->product?->product_name} | loc={$s->location_id}\n";
}

echo "\nDone.\n";
