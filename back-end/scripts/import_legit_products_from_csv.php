<?php

/**
 * Import products & inventory from OCR CSV.
 *
 * Usage (from back-end):
 *   php scripts/import_legit_products_from_csv.php storage/app/imports/legit_products_ocr.csv
 *
 * What it does:
 * - Upserts categories in `category_lookup`
 * - Upserts brands in `brand_lookup` (when provided)
 * - Upserts products in `products`
 * - Seeds inventory totals into ONE location (creates/uses "BOSSING INVENTORY" location)
 *
 * Notes:
 * - We intentionally ignore per-branch quantities; we store TOTAL ON HAND into a single location.
 * - Product uniqueness key: product_code (we generate from model_code + capacity/variant)
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$csvPath = $argv[1] ?? null;
if (!$csvPath) {
    fwrite(STDERR, "Missing CSV path.\n");
    exit(1);
}

$backendRoot = realpath(__DIR__ . '/..');
require $backendRoot . '/vendor/autoload.php';

$app = require $backendRoot . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function readCsv(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("CSV not found: {$path}");
    }
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return [];
    }
    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $data[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function slugCode(string $model, string $cap): string {
    $m = strtoupper(trim($model));
    $c = strtoupper(trim($cap));
    $c = preg_replace('/\s+/', '', $c);
    if ($c !== '') {
        // Model can already contain dashes; keep it readable
        return "{$m}-{$c}";
    }
    return $m;
}

$rows = readCsv($csvPath);
if (count($rows) === 0) {
    fwrite(STDERR, "No rows found in CSV.\n");
    exit(1);
}

DB::beginTransaction();
try {
    // 1) Ensure a default location exists (we'll store totals here)
    $locationName = 'BOSSING INVENTORY';
    $locId = DB::table('locations')->where('location_name', $locationName)->value('location_id');
    if (!$locId) {
        $locId = DB::table('locations')->insertGetId([
            'location_name' => $locationName,
            'address' => null,
            'status_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'location_id');
    }

    // 2) Load existing lookup maps
    $categories = DB::table('category_lookup')->pluck('category_id', 'category_name')->all();
    $brands = DB::table('brand_lookup')->pluck('brand_id', 'brand_name')->all();
    $units = DB::table('unit_lookup')->pluck('unit_id', 'unit_name')->all();

    $unitIdForHint = function (?string $hint) use ($units) {
        $h = strtolower(trim((string) $hint));
        if ($h === '') return null;
        foreach ($units as $name => $id) {
            if (strtolower($name) === $h) return $id;
        }
        // fallback common
        foreach ($units as $name => $id) {
            $n = strtolower($name);
            if ($h === 'unit' && $n === 'unit') return $id;
            if ($h === 'box' && $n === 'box') return $id;
            if ($h === 'roll' && $n === 'roll') return $id;
            if ($h === 'pack' && $n === 'pack') return $id;
        }
        return null;
    };

    $createdProducts = 0;
    $updatedProducts = 0;
    $upsertedInventory = 0;

    foreach ($rows as $r) {
        $categoryName = trim((string)($r['category_name'] ?? ''));
        $brandName = trim((string)($r['brand_name'] ?? ''));
        $productType = trim((string)($r['product_type'] ?? 'appliance')) ?: 'appliance';
        $modelCode = trim((string)($r['model_code'] ?? ''));
        $cap = trim((string)($r['capacity_or_variant'] ?? ''));
        $unitHint = trim((string)($r['unit_hint'] ?? ''));
        $total = (int)($r['total_on_hand'] ?? 0);

        if ($categoryName === '' || $modelCode === '') {
            continue;
        }

        // Category
        if (!isset($categories[$categoryName])) {
            $catId = DB::table('category_lookup')->insertGetId([
                'category_name' => $categoryName,
                'description' => null,
                'category_type' => 'product',
                'created_at' => now(),
                'updated_at' => now(),
            ], 'category_id');
            $categories[$categoryName] = $catId;
        }
        $categoryId = $categories[$categoryName];

        // Brand
        $brandId = null;
        if ($brandName !== '') {
            if (!isset($brands[$brandName])) {
                $bid = DB::table('brand_lookup')->insertGetId([
                    'brand_name' => $brandName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'brand_id');
                $brands[$brandName] = $bid;
            }
            $brandId = $brands[$brandName];
        }

        // Unit (best-effort)
        $unitId = $unitIdForHint($unitHint);

        // Product code + name
        $productCode = slugCode($modelCode, $cap);
        $nameParts = [];
        if ($brandName !== '') $nameParts[] = $brandName;
        $nameParts[] = $modelCode;
        if ($cap !== '') $nameParts[] = $cap;
        $productName = implode(' ', $nameParts);

        $existing = DB::table('products')->where('product_code', $productCode)->first();
        if (!$existing) {
            $pid = DB::table('products')->insertGetId([
                'product_code' => $productCode,
                'product_name' => $productName,
                'product_type' => in_array($productType, ['appliance', 'consumable']) ? $productType : 'appliance',
                'capacity_rating' => (preg_match('/\b\d(?:\.\d)?HP\b/i', $cap) ? strtoupper($cap) : null),
                'description' => null,
                'pieces_per_package' => null,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $unitId,
                'unit_price' => 0,
                'cost_price' => 0,
                'warranty_period_months' => 0,
                'status_id' => null,
                'recommended_stocks' => 0,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'product_id');
            $productId = $pid;
            $createdProducts++;
        } else {
            $productId = $existing->product_id;
            DB::table('products')->where('product_id', $productId)->update([
                'product_name' => $productName,
                'product_type' => in_array($productType, ['appliance', 'consumable']) ? $productType : 'appliance',
                'capacity_rating' => (preg_match('/\b\d(?:\.\d)?HP\b/i', $cap) ? strtoupper($cap) : ($existing->capacity_rating ?? null)),
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $unitId,
                'updated_at' => now(),
            ]);
            $updatedProducts++;
        }

        // Inventory upsert (single location)
        $inv = DB::table('inventory')
            ->where('location_id', $locId)
            ->where('product_id', $productId)
            ->first();
        if (!$inv) {
            DB::table('inventory')->insert([
                'location_id' => $locId,
                'product_id' => $productId,
                'quantity_on_hand' => $total,
                'available_quantity' => $total,
                'reorder_level' => 0,
                'last_updated' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('inventory')->where('inventory_id', $inv->inventory_id)->update([
                'quantity_on_hand' => $total,
                'available_quantity' => $total,
                'last_updated' => now(),
                'updated_at' => now(),
            ]);
        }
        $upsertedInventory++;
    }

    DB::commit();

    fwrite(STDOUT, "Import complete.\n");
    fwrite(STDOUT, "Location: {$locationName} (ID {$locId})\n");
    fwrite(STDOUT, "Products created: {$createdProducts}\n");
    fwrite(STDOUT, "Products updated: {$updatedProducts}\n");
    fwrite(STDOUT, "Inventory upserted: {$upsertedInventory}\n");
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "Import failed: {$e->getMessage()}\n");
    exit(1);
}

