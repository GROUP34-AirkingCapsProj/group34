<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::transaction(function () {
    $products = Illuminate\Support\Facades\DB::table('products')
        ->select('product_id')
        ->orderBy('product_id')
        ->get();

    foreach ($products as $i => $p) {
        // Mix of healthy and low stock so PO Recommendation shows alerts.
        $qty = ($i % 3 === 0) ? 30 : (($i % 3 === 1) ? 18 : 12);

        Illuminate\Support\Facades\DB::table('products')
            ->where('product_id', $p->product_id)
            ->update([
                'recommended_stocks' => 20,
                'updated_at' => now(),
            ]);

        Illuminate\Support\Facades\DB::table('inventory')->updateOrInsert(
            [
                'location_id' => 1,
                'product_id' => $p->product_id,
            ],
            [
                'quantity_on_hand' => $qty,
                'available_quantity' => $qty,
                'reorder_level' => 20,
                'last_updated' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
});

echo "Seeded demo quantities successfully.\n";

