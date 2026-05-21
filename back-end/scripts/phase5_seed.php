<?php
// Seed an in-stock appliance serial for Phase 5 tests
// First find an appliance product
$appliance = \App\Models\ApplianceDetail::with('product')->first();
if (!$appliance) { echo "No appliance products found\n"; exit; }

$product = $appliance->product;
echo "Using appliance product: {$product->product_name} (ID={$product->product_id})\n";

// Delete old test serials to avoid duplicates
\App\Models\ItemSerial::where('serial_number', 'LIKE', 'TEST-P5-%')->delete();

// Create a fresh in-stock serial
$serial = \App\Models\ItemSerial::create([
    'product_id'    => $product->product_id,
    'serial_number' => 'TEST-P5-SN-001',
    'status_id'     => 26, // In Stock
    'location_id'   => 1,
    'scanned_by'    => 2,
]);
echo "Created serial: TEST-P5-SN-001 (ID={$serial->id})\n";
echo "STATUS_ID=26 (In Stock), LOCATION_ID=1\n";
echo "PRODUCT: {$product->product_name} | PRICE: {$product->unit_price}\n";
