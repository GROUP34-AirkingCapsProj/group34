<?php
// Phase 4 Part E — End-to-end verification tests

// ── TEST 1: Solvent quick actions ────────────────────────────────────────────
echo "=== TEST 1: Solvent (product_id=2) quick actions ===\n";
$solvent = \App\Models\Product::find(2);
$qa = $solvent->buildConsumableQuickActions();
foreach ($qa as $a) {
    $btn  = $a['show_as_quick_action'] ? 'BUTTON' : 'dropdown';
    $bulk = $a['is_whole_package']     ? ' [bulk]' : '';
    $cust = $a['is_custom']            ? ' [custom]' : '';
    $pcs  = $a['quantity_in_pieces']   ?? 'null';
    $cf   = $a['conversion_factor']    ?? 'null';
    echo "  [{$btn}]{$bulk}{$cust} \"{$a['label']}\" pieces={$pcs} cf={$cf} preview={$a['price_preview']}\n";
}
$has = $solvent->issuableUnits->isNotEmpty();
echo "  has_issuable_units=" . ($has ? 'true' : 'false') . "\n";

// ── TEST 2: Copper Tube quick actions ────────────────────────────────────────
echo "\n=== TEST 2: Copper Tube (product_id=4) quick actions ===\n";
$copper = \App\Models\Product::find(4);
$qa2 = $copper->buildConsumableQuickActions();
foreach ($qa2 as $a) {
    $btn  = $a['show_as_quick_action'] ? 'BUTTON' : 'dropdown';
    $bulk = $a['is_whole_package']     ? ' [bulk]' : '';
    $cust = $a['is_custom']            ? ' [custom]' : '';
    $pcs  = $a['quantity_in_pieces']   ?? 'null';
    $cf   = $a['conversion_factor']    ?? 'null';
    echo "  [{$btn}]{$bulk}{$cust} \"{$a['label']}\" pieces={$pcs} cf={$cf} preview={$a['price_preview']}\n";
}
$has2 = $copper->issuableUnits->isNotEmpty();
echo "  has_issuable_units=" . ($has2 ? 'true' : 'false') . "\n";

// ── TEST 3: Copper Tube dropdown units ───────────────────────────────────────
echo "\n=== TEST 3: Copper Tube dropdown units ===\n";
$du = $copper->getIssuableDropdownUnits();
foreach ($du as $u) {
    $uArr = (array) $u;
    echo "  [{$uArr['unit_id']}] {$uArr['unit_name']} ({$uArr['unit_abbreviation']})\n";
}
echo "  Count=" . count($du) . " (expect: product-specific units only, not all 18)\n";

// ── TEST 4: Add a new issuable unit and verify it appears ───────────────────
echo "\n=== TEST 4: Add issuable unit to Solvent, verify it appears ===\n";
$newIU = \App\Models\ProductIssuableUnit::firstOrCreate(
    ['product_id' => 2, 'label' => 'Test 5-Pack'],
    [
        'unit_id'           => 15, // Pack
        'conversion_factor' => 5.0,
        'is_whole_package'  => false,
        'sort_order'        => 10,
        'show_as_quick_action' => true,
    ]
);
echo "  Created/found issuable_unit_id=" . $newIU->issuable_unit_id . "\n";

$fresh = \App\Models\Product::find(2);
$qa3 = $fresh->buildConsumableQuickActions();
$found = collect($qa3)->firstWhere('label', 'Test 5-Pack');
echo "  'Test 5-Pack' appears in quick_actions: " . ($found ? 'YES' : 'NO') . "\n";
if ($found) {
    echo "  pieces={$found['quantity_in_pieces']} cf={$found['conversion_factor']}\n";
}
// Clean up test row
$newIU->delete();
echo "  Cleaned up test row.\n";

// ── TEST 5: Product with NO issuable units falls back to default ─────────────
echo "\n=== TEST 5: Product with no issuable units → fallback quick actions ===\n";
// Find a consumable product that has no issuable_units rows
$fallbackProduct = \App\Models\Product::where('product_type', 'consumable')
    ->whereDoesntHave('issuableUnits')
    ->first();
if ($fallbackProduct) {
    $qaFallback = $fallbackProduct->buildConsumableQuickActions();
    echo "  Product: {$fallbackProduct->product_name} (id={$fallbackProduct->product_id})\n";
    echo "  Quick actions count=" . count($qaFallback) . "\n";
    foreach ($qaFallback as $a) {
        $btn  = $a['show_as_quick_action'] ? 'BUTTON' : 'dropdown';
        $cust = $a['is_custom']            ? ' [custom]' : '';
        echo "  [{$btn}]{$cust} \"{$a['label']}\"\n";
    }
    echo "  has_issuable_units=false (expected)\n";
} else {
    echo "  No consumable product without issuable units found — all products have issuable units seeded.\n";
}

// ── TEST 6: BarcodeScan and IssuanceScan controller responses ────────────────
echo "\n=== TEST 6: Verify controller response shape ===\n";
// Simulate what BarcodeScanController does for Solvent
$p = \App\Models\Product::with(['issuableUnits.unit', 'consumableDetail.unit'])->find(2);
$quickActions = $p->buildConsumableQuickActions();
$dropdownUnits = $p->getIssuableDropdownUnits();
$hasIssuable = $p->issuableUnits->isNotEmpty();
echo "  quick_actions count=" . count($quickActions) . "\n";
echo "  dropdown_units count=" . count($dropdownUnits) . "\n";
echo "  has_issuable_units=" . ($hasIssuable ? 'true' : 'false') . "\n";
echo "  Keys in first quick_action: " . implode(', ', array_keys($quickActions[0] ?? [])) . "\n";

echo "\n=== ALL TESTS COMPLETE ===\n";
