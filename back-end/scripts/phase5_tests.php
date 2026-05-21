<?php
// Phase 5 Part C — API Tests 1-7
$TOKEN   = '46|KrYZgT89xAd2HXHh8Jpuzn66fZTefCu7jxft8y7r30721eda';
$BASE    = 'http://localhost/Airking_Inventory/back-end/public/api';
$LOC_ID  = 1;
$PASS    = 'PASS';
$FAIL    = 'FAIL';
$JSX_PATH = 'C:/XAMPP1/htdocs/Airking_Inventory/frontend/src/admin/pages/IssuanceScanPage.jsx';

function apiCall($method, $url, $token, $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Accept: application/json",
        "Content-Type: application/json",
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($resp, true) ?? [], 'raw' => $resp];
}

// ── Fresh setup: re-seed serial and new issuance session ─────────────
\App\Models\ItemSerial::where('serial_number', 'LIKE', 'TEST-P5-%')->delete();
$appliance = \App\Models\ApplianceDetail::with('product')->first();
\App\Models\ItemSerial::create([
    'product_id'    => $appliance->product_id,
    'serial_number' => 'TEST-P5-SN-002',
    'status_id'     => 26,
    'location_id'   => $LOC_ID,
    'scanned_by'    => 2,
]);
echo "Seeded: TEST-P5-SN-002 (product: {$appliance->product->product_name} price: {$appliance->product->unit_price})\n";

$sessR = apiCall('POST', "$BASE/issuance-scan/init", $TOKEN, [
    'customer_id'      => 1,
    'issuance_type'    => 'individual',
    'discount_type'    => 'none',
    'discount_percent' => 0,
    'location_id'      => $LOC_ID,
    'notes'            => 'Phase 5 Part C tests',
]);
$ISS_ID = $sessR['body']['issuance_id'] ?? null;
echo "Session: issuance_id=$ISS_ID dr={$sessR['body']['dr_number']}\n\n";

if (!$ISS_ID) { echo "ABORT: could not create session.\n"; exit(1); }

// ─────────────────────────────────────────────────────────────────────
// TEST 1: Physical scanner simulation — appliance serial
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 1: Appliance serial scan ===\n";
$lookup1 = apiCall('POST', "$BASE/barcode/scan", $TOKEN, ['barcode' => 'TEST-P5-SN-002']);
$ld1     = $lookup1['body']['data'] ?? [];
$isSer   = $ld1['is_serialized'] ?? false;
echo "  Lookup: status={$ld1['status']} is_serialized=" . ($isSer ? 'true' : 'false') . "\n";
// is_serialized=true OR NOT_FOUND → applyApplianceScan
$scan1 = apiCall('POST', "$BASE/issuance-scan/scan", $TOKEN, [
    'issuance_id' => $ISS_ID,
    'barcode'     => 'TEST-P5-SN-002',
    'location_id' => $LOC_ID,
]);
$sd1 = $scan1['body'];
echo "  Scan: success=" . ($sd1['success'] ? 'true' : 'false') . " action=" . ($sd1['action'] ?? '?') . "\n";
echo "  product={$sd1['product_name']} serial={$sd1['serial_number']} subtotal={$sd1['subtotal']}\n";
$t1 = ($sd1['success'] ?? false) && ($sd1['action'] ?? '') === 'applied' && ($sd1['serial_number'] ?? '') === 'TEST-P5-SN-002';
echo "  RESULT: " . ($t1 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 2: Consumable qty panel — frontend logic branch
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 2: Consumable qty panel (barcode lookup branch) ===\n";
$lookup2 = apiCall('POST', "$BASE/barcode/scan", $TOKEN, ['barcode' => 'PROD-4E838B48']);
$ld2     = $lookup2['body']['data'] ?? [];
$isSer2  = $ld2['is_serialized'] ?? true;
echo "  Lookup: status={$ld2['status']} is_serialized=" . ($isSer2 ? 'true' : 'false') . "\n";
echo "  unit_name={$ld2['unit_name']} pieces_per_package={$ld2['pieces_per_package']}\n";
$pname2 = $ld2['product']['product_name'] ?? '?';
$price2 = $ld2['product']['unit_price'] ?? 0;
echo "  product=$pname2 price=$price2\n";
$showQtyPanel = ($ld2['status'] === 'FOUND') && !$isSer2 && !empty($ld2['product']);
echo "  Frontend routes to qty panel: " . ($showQtyPanel ? 'YES' : 'NO') . "\n";
$ppp2 = intval($ld2['pieces_per_package'] ?? 1);
$ppp2 = $ppp2 > 0 ? $ppp2 : 1;
$pricePerPiece2 = round(floatval($price2) / $ppp2, 4);
echo "  price_per_piece = $price2 / $ppp2 = $pricePerPiece2\n";
$t2 = $showQtyPanel;
echo "  RESULT: " . ($t2 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 3: Rejected scan — completely unknown barcode
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 3: Rejected scan — unknown barcode ===\n";
$lookup3 = apiCall('POST', "$BASE/barcode/scan", $TOKEN, ['barcode' => 'NO-SUCH-BARCODE-XYZ99']);
$ld3     = $lookup3['body']['data'] ?? [];
echo "  Lookup: status={$ld3['status']}\n";
$scan3 = apiCall('POST', "$BASE/issuance-scan/scan", $TOKEN, [
    'issuance_id' => $ISS_ID,
    'barcode'     => 'NO-SUCH-BARCODE-XYZ99',
    'location_id' => $LOC_ID,
]);
$sd3 = $scan3['body'];
echo "  Scan: success=" . ($sd3['success'] ? 'true' : 'false') . " reason=" . ($sd3['reason'] ?? 'N/A') . "\n";
$t3 = !($sd3['success'] ?? true) && !empty($sd3['reason']);
echo "  RESULT: " . ($t3 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 4: Duplicate appliance serial — scan TEST-P5-SN-002 again
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 4: Duplicate appliance serial (already issued) ===\n";
$scan4 = apiCall('POST', "$BASE/issuance-scan/scan", $TOKEN, [
    'issuance_id' => $ISS_ID,
    'barcode'     => 'TEST-P5-SN-002',
    'location_id' => $LOC_ID,
]);
$sd4 = $scan4['body'];
echo "  Scan: success=" . ($sd4['success'] ? 'true' : 'false') . " reason=" . ($sd4['reason'] ?? 'N/A') . "\n";
$t4 = !($sd4['success'] ?? true) && !empty($sd4['reason']);
echo "  RESULT: " . ($t4 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 5: Cancel quantity panel — JSX code verification
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 5: Cancel quantity panel — JSX code ===\n";
$jsx = file_get_contents($JSX_PATH);
$hasCancelHandler  = strpos($jsx, 'setPendingScan(null)') !== false;
$hasScanClear      = strpos($jsx, "setScanValue('')") !== false;
$hasQtyCancelClass = strpos($jsx, 'qty-panel-cancel') !== false;
echo "  setPendingScan(null): " . ($hasCancelHandler ? 'YES' : 'NO') . "\n";
echo "  setScanValue(''): " . ($hasScanClear ? 'YES' : 'NO') . "\n";
echo "  qty-panel-cancel class: " . ($hasQtyCancelClass ? 'YES' : 'NO') . "\n";
$t5 = $hasCancelHandler && $hasScanClear && $hasQtyCancelClass;
echo "  RESULT: " . ($t5 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 6: Keyboard-only flow — JSX code verification
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 6: Keyboard-only flow — JSX code ===\n";
$hasEnterKey      = strpos($jsx, "e.key === 'Enter'") !== false;
$hasScanRef       = strpos($jsx, 'scanInputRef') !== false;
$hasQtyEnter      = strpos($jsx, 'applyConsumableScan') !== false;
$hasAutoFocus     = strpos($jsx, 'focus()') !== false || strpos($jsx, 'focus(') !== false;
$hasRefAttr       = strpos($jsx, 'ref={scanInputRef}') !== false;
echo "  Enter key handler: " . ($hasEnterKey ? 'YES' : 'NO') . "\n";
echo "  scanInputRef wired (ref={}): " . ($hasRefAttr ? 'YES' : 'NO') . "\n";
echo "  qty Enter → applyConsumableScan: " . ($hasQtyEnter ? 'YES' : 'NO') . "\n";
echo "  focus() calls present: " . ($hasAutoFocus ? 'YES' : 'NO') . "\n";
$t6 = $hasEnterKey && $hasScanRef && $hasQtyEnter && $hasAutoFocus && $hasRefAttr;
echo "  RESULT: " . ($t6 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// TEST 7: Copper tube partial quantity — 2.5 Meter
// ─────────────────────────────────────────────────────────────────────
echo "=== TEST 7: Copper tube partial quantity (2.5 Meter) ===\n";
$scan7 = apiCall('POST', "$BASE/issuance-scan/scan", $TOKEN, [
    'issuance_id'  => $ISS_ID,
    'barcode'      => 'PROD-4E838B48',
    'quantity'     => 2.5,
    'quantity_unit' => 'Meter',
    'location_id'  => $LOC_ID,
]);
$sd7 = $scan7['body'];
echo "  HTTP: {$scan7['status']}\n";
echo "  success=" . ($sd7['success'] ? 'true' : 'false') . " action=" . ($sd7['action'] ?? '?') . "\n";
echo "  product=" . ($sd7['product_name'] ?? '?') . " qty=" . ($sd7['quantity_issued'] ?? '?') . " unit=" . ($sd7['quantity_unit'] ?? '?') . "\n";
echo "  subtotal=" . ($sd7['subtotal'] ?? '?') . " price_per_piece=" . ($sd7['price_per_piece'] ?? '?') . "\n";
$t7 = ($sd7['success'] ?? false) && ($sd7['action'] ?? '') === 'applied' && floatval($sd7['quantity_issued'] ?? 0) == 2.5;
echo "  RESULT: " . ($t7 ? $PASS : $FAIL) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────────
echo "=== PHASE 5 PART C — SUMMARY ===\n";
$results = [
    'T1 Appliance serial scan'     => $t1,
    'T2 Consumable qty panel route' => $t2,
    'T3 Unknown barcode rejected'  => $t3,
    'T4 Duplicate serial rejected' => $t4,
    'T5 Cancel qty panel (JSX)'    => $t5,
    'T6 Keyboard-only flow (JSX)'  => $t6,
    'T7 Partial qty 2.5 Meter'     => $t7,
];
$passed = 0;
foreach ($results as $name => $ok) {
    echo "  " . ($ok ? '[PASS]' : '[FAIL]') . " $name\n";
    if ($ok) $passed++;
}
echo "\n  $passed / " . count($results) . " tests passed\n";
