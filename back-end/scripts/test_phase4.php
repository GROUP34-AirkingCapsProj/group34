<?php
// Phase 4 test helper — run via: php artisan tinker --execute="require base_path('scripts/test_phase4.php');"

use App\Models\Customer;
use App\Models\ItemSerial;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\WarrantyRecord;

// --- Customers ---
echo "\n=== CUSTOMERS ===\n";
$customers = Customer::limit(5)->get(['customer_id','customer_name','customer_type']);
foreach ($customers as $c) {
    echo "  ID={$c->customer_id} | {$c->customer_name} | {$c->customer_type}\n";
}

// --- Serials in stock ---
echo "\n=== SERIALS IN STOCK (status_id=26) ===\n";
$serials = ItemSerial::where('status_id', 26)
    ->with('product.applianceDetail.brand')
    ->limit(10)->get();
foreach ($serials as $s) {
    $ad = $s->product?->applianceDetail;
    echo "  serial={$s->serial_number} | loc={$s->location_id}"
        . " | product={$s->product?->product_name}"
        . " | warranty_months=" . ($ad?->warranty_period_months ?? 0)
        . " | unit_price=" . ($s->product?->unit_price ?? 0) . "\n";
}

// --- Consumable inventory ---
echo "\n=== CONSUMABLE INVENTORY (qty > 0) ===\n";
$inv = Inventory::where('quantity_on_hand', '>', 0)
    ->with(['product.consumableDetail.unit'])
    ->get();
foreach ($inv as $i) {
    $p = $i->product;
    if ($p && !$p->isSerialized()) {
        $cd = $p->consumableDetail;
        echo "  product={$p->product_name} | barcode={$p->barcode}"
            . " | qty={$i->quantity_on_hand} | loc={$i->location_id}"
            . " | unit=" . ($cd?->unit?->unit_name ?? 'N/A')
            . " | pkg_unit=" . ($cd?->package_unit ?? 'null')
            . " | pcs_per_pkg=" . ($cd?->pieces_per_package ?? 0) . "\n";
    }
}

// --- Recent issuances ---
echo "\n=== RECENT ISSUANCES (last 10) ===\n";
$issuances = Issuance::orderByDesc('issuance_id')->limit(10)->get();
foreach ($issuances as $i) {
    echo "  ID={$i->issuance_id} | DR={$i->dr_number} | type={$i->issuance_type}"
        . " | status={$i->status_id} | total={$i->total_amount} | disc%={$i->discount_percent}\n";
}

echo "\nDone.\n";
