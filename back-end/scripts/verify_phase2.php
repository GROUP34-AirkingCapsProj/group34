<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\WarrantyRecord;

echo '=== WarrantyRecord::count() ===' . PHP_EOL;
echo WarrantyRecord::count() . PHP_EOL;

echo PHP_EOL . '=== warranty_records columns ===' . PHP_EOL;
$cols = DB::select('SHOW COLUMNS FROM warranty_records');
foreach ($cols as $c) echo '  ' . $c->Field . PHP_EOL;

echo PHP_EOL . '=== issuances new columns ===' . PHP_EOL;
$needed = ['customer_id','dr_number','discount_type','discount_percent','subtotal','discount_amount','total_amount','notes'];
$cols = DB::select('SHOW COLUMNS FROM issuances');
$existing = array_column($cols, 'Field');
foreach ($needed as $n) {
    echo '  ' . $n . ': ' . (in_array($n, $existing) ? 'OK' : 'MISSING') . PHP_EOL;
}

echo PHP_EOL . '=== issuance_details new columns ===' . PHP_EOL;
$needed = ['serial_number','unit_price','quantity_unit','quantity_in_pieces','unit_price_per_piece','subtotal'];
$cols = DB::select('SHOW COLUMNS FROM issuance_details');
$existing = array_column($cols, 'Field');
foreach ($needed as $n) {
    echo '  ' . $n . ': ' . (in_array($n, $existing) ? 'OK' : 'MISSING') . PHP_EOL;
}

echo PHP_EOL . '=== delivery_receipts new columns ===' . PHP_EOL;
$needed = ['issuance_id','customer_id','location_id','dr_date','total_amount','notes'];
$cols = DB::select('SHOW COLUMNS FROM delivery_receipts');
$existing = array_column($cols, 'Field');
foreach ($needed as $n) {
    echo '  ' . $n . ': ' . (in_array($n, $existing) ? 'OK' : 'MISSING') . PHP_EOL;
}

echo PHP_EOL . '=== status_lookup rows 30-37 ===' . PHP_EOL;
$rows = DB::select('SELECT status_id, status_name, status_category FROM status_lookup WHERE status_id BETWEEN 30 AND 37 ORDER BY status_id');
foreach ($rows as $r) echo "  {$r->status_id}  {$r->status_name}  ({$r->status_category})" . PHP_EOL;

echo PHP_EOL . 'ALL CHECKS DONE' . PHP_EOL;
