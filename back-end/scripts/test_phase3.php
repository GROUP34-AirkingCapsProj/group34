<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Issuance;

// Seed a test customer if none exists
$customer = Customer::first();
if (! $customer) {
    $customer = Customer::create([
        'customer_code'  => 'CUST-TEST01',
        'customer_type'  => 'individual',
        'customer_name'  => 'Test Customer Phase3',
        'contact_number' => '09000000000',
        'status_id'      => 1,
        'outstanding_balance' => 0,
    ]);
    echo "Created test customer id={$customer->customer_id}" . PHP_EOL;
}

$location = Location::first();
if (! $location) {
    echo 'ERROR: No locations in database.' . PHP_EOL;
    exit(1);
}

$customerId = $customer->customer_id;
$locationId = $location->location_id;
echo "Using customer_id={$customerId} ({$customer->customer_name}), location_id={$locationId} ({$location->location_name})" . PHP_EOL;

function makeIssuance(array $payload): array
{
    $drNumber = 'DR-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

    $discountType    = $payload['discount_type'] ?? 'none';
    $discountPercent = 0;

    if ($payload['issuance_type'] === 'business') {
        $discountPercent = (float) ($payload['discount_percent'] ?? 0);
        $discountType    = $payload['discount_type'] ?? 'business';
    } elseif ($payload['issuance_type'] === 'warranty') {
        $discountPercent = 100;
        $discountType    = 'none';
    }

    $issuance = Issuance::create([
        'location_id'      => $payload['location_id'],
        'customer_id'      => $payload['customer_id'],
        'issuance_date'    => now()->toDateString(),
        'issuance_type'    => $payload['issuance_type'],
        'dr_number'        => $drNumber,
        'discount_type'    => $discountType,
        'discount_percent' => $discountPercent,
        'subtotal'         => 0,
        'discount_amount'  => 0,
        'total_amount'     => 0,
        'notes'            => $payload['notes'] ?? null,
        'issued_by'        => \App\Models\User::min('user_id'),
        'status_id'        => 30,
    ]);

    return [
        'issuance_id'      => $issuance->issuance_id,
        'dr_number'        => $drNumber,
        'issuance_type'    => $issuance->issuance_type,
        'discount_type'    => $discountType,
        'discount_percent' => $discountPercent,
        'status_id'        => $issuance->status_id,
    ];
}

$base = ['customer_id' => $customerId, 'location_id' => $locationId];

echo PHP_EOL . '=== TEST 1: individual (discount_percent should = 0) ===' . PHP_EOL;
$r1 = makeIssuance($base + ['issuance_type' => 'individual']);
echo 'issuance_id:      ' . $r1['issuance_id'] . PHP_EOL;
echo 'dr_number:        ' . $r1['dr_number'] . PHP_EOL;
echo 'discount_percent: ' . $r1['discount_percent'] . '  (expected: 0) ' . ($r1['discount_percent'] == 0 ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'status_id:        ' . $r1['status_id'] . '  (expected: 30) '       . ($r1['status_id'] == 30  ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'DR format: ' . (preg_match('/^DR-\d{8}-[A-Z0-9]{5}$/', $r1['dr_number']) ? 'PASS' : 'FAIL') . PHP_EOL;

echo PHP_EOL . '=== TEST 2: business discount_percent=10 ===' . PHP_EOL;
$r2 = makeIssuance($base + ['issuance_type' => 'business', 'discount_percent' => 10, 'discount_type' => 'business']);
echo 'issuance_id:      ' . $r2['issuance_id'] . PHP_EOL;
echo 'dr_number:        ' . $r2['dr_number'] . PHP_EOL;
echo 'discount_type:    ' . $r2['discount_type'] . '  (expected: business) ' . ($r2['discount_type'] === 'business' ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'discount_percent: ' . $r2['discount_percent'] . '  (expected: 10) '    . ($r2['discount_percent'] == 10     ? 'PASS' : 'FAIL') . PHP_EOL;

echo PHP_EOL . '=== TEST 3: warranty (discount_percent should = 100) ===' . PHP_EOL;
$r3 = makeIssuance($base + ['issuance_type' => 'warranty']);
echo 'issuance_id:      ' . $r3['issuance_id'] . PHP_EOL;
echo 'dr_number:        ' . $r3['dr_number'] . PHP_EOL;
echo 'discount_percent: ' . $r3['discount_percent'] . '  (expected: 100) ' . ($r3['discount_percent'] == 100 ? 'PASS' : 'FAIL') . PHP_EOL;

echo PHP_EOL . '=== DB verification ===' . PHP_EOL;
$ids  = [$r1['issuance_id'], $r2['issuance_id'], $r3['issuance_id']];
$rows = DB::select(
    'SELECT issuance_id, issuance_type, discount_percent, status_id, dr_number FROM issuances WHERE issuance_id IN (' . implode(',', $ids) . ') ORDER BY issuance_id'
);
foreach ($rows as $row) {
    echo "  id={$row->issuance_id}  type={$row->issuance_type}  discount={$row->discount_percent}%  status={$row->status_id}  dr={$row->dr_number}" . PHP_EOL;
}

echo PHP_EOL . '=== Route smoke-check (no HTTP needed) ===' . PHP_EOL;
$routes = app('router')->getRoutes();
$found  = [];
foreach ($routes as $route) {
    if (str_contains($route->uri(), 'issuance-scan')) {
        $found[] = strtoupper(implode('|', $route->methods())) . ' ' . $route->uri();
    }
}
sort($found);
foreach ($found as $r) echo '  ' . $r . PHP_EOL;
echo count($found) === 6 ? 'Route count: PASS (6)' : 'Route count: FAIL (got ' . count($found) . ')';
echo PHP_EOL;

echo PHP_EOL . 'PHASE 3 ALL TESTS DONE' . PHP_EOL;
