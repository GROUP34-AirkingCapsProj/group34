<?php
// Print consumable product details for test reference
use App\Models\Product;

$consumables = Product::where('product_type', 'consumable')
    ->with('consumableDetail.unit')
    ->limit(10)->get();

echo "=== CONSUMABLE PRODUCTS ===\n";
foreach ($consumables as $p) {
    $cd = $p->consumableDetail;
    echo "  ID={$p->product_id} | {$p->product_name}"
        . " | barcode='" . ($p->barcode ?? '') . "'"
        . " | code='" . ($p->product_code ?? '') . "'"
        . " | price={$p->unit_price}"
        . " | unit=" . ($cd?->unit?->unit_name ?? 'N/A')
        . " | pcs=" . ($cd?->pieces_per_package ?? 0) . "\n";
}
echo "\nDone.\n";
