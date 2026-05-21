<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::select('SELECT status_id FROM status_lookup WHERE status_id BETWEEN 30 AND 37');
if (count($rows) > 0) {
    $ids = array_column($rows, 'status_id');
    echo 'CONFLICTS: ' . implode(', ', $ids) . PHP_EOL;
    exit(1);
}

DB::table('status_lookup')->insert([
    ['status_id' => 30, 'status_name' => 'Pending',   'status_category' => 'issuance', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 31, 'status_name' => 'Completed', 'status_category' => 'issuance', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 32, 'status_name' => 'Voided',    'status_category' => 'issuance', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 33, 'status_name' => 'Active',    'status_category' => 'warranty', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 34, 'status_name' => 'Expired',   'status_category' => 'warranty', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 35, 'status_name' => 'Claimed',   'status_category' => 'warranty', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 36, 'status_name' => 'Active',    'status_category' => 'customer', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['status_id' => 37, 'status_name' => 'Inactive',  'status_category' => 'customer', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
]);

$inserted = DB::select('SELECT status_id, status_name, status_category FROM status_lookup WHERE status_id BETWEEN 30 AND 37');
echo 'INSERTED ' . count($inserted) . ' rows:' . PHP_EOL;
foreach ($inserted as $r) {
    echo "  {$r->status_id}  {$r->status_name}  ({$r->status_category})" . PHP_EOL;
}
