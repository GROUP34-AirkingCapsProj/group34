<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fills empty demo/test detail fields on existing products without touching
 * any value that is already populated.
 *
 * Idempotent: every field is written only when currently NULL / empty / 0, so
 * re-running never clobbers real data entered later. All writes go through the
 * query builder inside one transaction to stay fast and avoid the Auditable
 * trait flooding audit_trail with hundreds of rows.
 *
 * Run standalone (NOT part of the fresh-install DatabaseSeeder):
 *   php artisan db:seed --class=DemoProductDataSeeder
 *
 * Values are synthetic demo data — realistic but not real business figures.
 */
class DemoProductDataSeeder extends Seeder
{
    public function run(): void
    {
        $before = $this->nullCounts();

        DB::transaction(function () {
            $rows = DB::table('products as p')
                ->leftJoin('appliance_details as a', 'a.product_id', '=', 'p.product_id')
                ->leftJoin('category_lookup as cat', 'cat.category_id', '=', 'p.category_id')
                ->leftJoin('brand_lookup as b', 'b.brand_id', '=', 'a.brand_id')
                ->whereNull('p.deleted_at')
                ->get([
                    'p.product_id', 'p.product_code', 'p.product_name', 'p.product_type',
                    'p.barcode', 'p.description', 'p.unit_price', 'p.cost_price',
                    'p.recommended_stocks', 'p.safety_stock', 'p.max_stock_level',
                    'cat.category_name',
                    'a.model_code', 'a.capacity_rating', 'a.variant', 'a.color',
                    'a.warranty_period_months', 'b.brand_name',
                ]);

            foreach ($rows as $r) {
                $this->fillProductRow($r);
                if ($r->product_type === 'appliance') {
                    $this->fillApplianceRow($r);
                } else {
                    $this->fillConsumableRow($r);
                }
            }
        });

        $after = $this->nullCounts();
        $this->report($before, $after);
    }

    // -----------------------------------------------------------------------
    // products
    // -----------------------------------------------------------------------

    private function fillProductRow(object $r): void
    {
        $id     = (int) $r->product_id;
        $update = [];

        $unitPrice = (float) $r->unit_price;
        if ($unitPrice <= 0) {
            $unitPrice = $this->unitPriceFor($r);
            $update['unit_price'] = $unitPrice;
        }

        if ((float) $r->cost_price <= 0) {
            $factor = $this->rngFloat($id, 'cost', 0.68, 0.78);
            $update['cost_price'] = round($unitPrice * $factor, 2);
        }

        $recommended = (int) $r->recommended_stocks;
        if ($recommended <= 0) {
            $recommended = $r->product_type === 'appliance'
                ? $this->rngInt($id, 'rec', 5, 20)
                : $this->rngInt($id, 'rec', 30, 200);
            $update['recommended_stocks'] = $recommended;
        }

        if ($r->safety_stock === null) {
            $update['safety_stock'] = max(1, (int) round($recommended * 0.35));
        }

        if ($r->max_stock_level === null) {
            $update['max_stock_level'] = max($recommended + 1, (int) round($recommended * 2.5));
        }

        if ($this->isBlank($r->barcode)) {
            $update['barcode'] = $this->ean13($id);
        }

        if ($this->isBlank($r->description)) {
            $update['description'] = $this->descriptionFor($r);
        }

        if ($update) {
            $update['updated_at'] = now();
            DB::table('products')->where('product_id', $id)->update($update);
        }
    }

    private function unitPriceFor(object $r): float
    {
        $id   = (int) $r->product_id;
        $cat  = $r->category_name ?? '';
        $name = $r->product_name ?? '';

        if ($r->product_type === 'installation_material') {
            $base = $this->consumableBasePrice($name);
        } elseif ($cat === 'Air Conditioning') {
            $base = $this->aircraftHpPrice($r->capacity_rating);
        } elseif ($cat === 'Television') {
            $base = $this->tvPrice($name);
        } elseif ($cat === 'Refrigerator') {
            $base = $this->rngInt($id, 'fridge', 15000, 45000);
        } elseif ($cat === 'Washing Machine') {
            $base = $this->rngInt($id, 'washer', 12000, 35000);
        } else { // Small Appliances / anything else
            $base = $this->rngInt($id, 'small', 1000, 5000);
        }

        // ±6% deterministic jitter so identical specs don't share one price.
        $jitter = $this->rngFloat($id, 'jitter', 0.94, 1.06);
        return round($base * $jitter, 2);
    }

    /** Base aircon price by horsepower rating. */
    private function aircraftHpPrice(?string $capacity): int
    {
        $map = [
            '0.6HP' => 18000, '0.8HP' => 20000, '1HP' => 28000, '1.5HP' => 38000,
            '2HP' => 52000, '2.5HP' => 62000, '3HP' => 75000, '4HP' => 95000,
            '5HP' => 120000, '6HP' => 145000, '7HP' => 170000,
        ];
        return $map[$capacity] ?? 30000;
    }

    /** TV price by screen size parsed from the name; soundbars priced separately. */
    private function tvPrice(string $name): int
    {
        if (stripos($name, 'soundbar') !== false) {
            return $this->rngInt(crc32($name), 'soundbar', 5000, 12000);
        }

        $size = null;
        if (preg_match('/\b(\d{2,3})\b/', $name, $m)) {
            $n = (int) $m[1];
            if ($n >= 24 && $n <= 98) {
                $size = $n;
            }
        }

        $tiers = [32 => 9000, 40 => 15000, 43 => 18000, 50 => 28000, 55 => 35000, 65 => 55000];
        if ($size === null) {
            return 15000;
        }
        if (isset($tiers[$size])) {
            return $tiers[$size];
        }
        // Nearest defined tier for off-list sizes (e.g. 48", 75").
        $nearest = 15000;
        $bestGap = PHP_INT_MAX;
        foreach ($tiers as $s => $price) {
            $gap = abs($s - $size);
            if ($gap < $bestGap) {
                $bestGap = $gap;
                $nearest = $price;
            }
        }
        return $nearest;
    }

    /** Consumable price by keyword in the product name. */
    private function consumableBasePrice(string $name): int
    {
        $seed = crc32($name);
        $n = strtolower($name);

        if (str_contains($n, 'copper tube')) {
            return str_contains($n, 'roll')
                ? $this->rngInt($seed, 'cu-roll', 1200, 2500)
                : $this->rngInt($seed, 'cu-foot', 60, 120);
        }
        if (str_contains($n, 'pe foam') || str_contains($n, 'insulation')) {
            return $this->rngInt($seed, 'foam', 150, 400);
        }
        return $this->rngInt($seed, 'part', 50, 800);
    }

    private function descriptionFor(object $r): string
    {
        if ($r->product_type === 'installation_material') {
            return trim(($r->product_name ?? 'Consumable supply item') . '.');
        }

        $brand   = $this->isBlank($r->brand_name) ? '' : $r->brand_name . ' ';
        $variant = $this->isBlank($r->variant) ? '' : $r->variant . ' ';
        $cap     = $this->isBlank($r->capacity_rating) ? '' : $r->capacity_rating . ' ';

        $noun = match ($r->category_name) {
            'Air Conditioning' => (stripos($r->variant ?? '', 'window') !== false)
                ? 'window-type air conditioner'
                : 'split-type air conditioner',
            'Television'       => 'television',
            'Refrigerator'     => 'refrigerator',
            'Washing Machine'  => 'washing machine',
            default            => 'home appliance',
        };

        $sentence = trim($brand . $variant . $cap . $noun) . '.';
        $sentence = ucfirst($sentence);

        $months = (int) ($r->warranty_period_months ?? 0);
        if ($months > 0) {
            $sentence .= " {$months}-month warranty.";
        }
        return $sentence;
    }

    // -----------------------------------------------------------------------
    // appliance_details
    // -----------------------------------------------------------------------

    private function fillApplianceRow(object $r): void
    {
        $update = [];

        if ($this->isBlank($r->model_code)) {
            $update['model_code'] = $this->modelCodeFor($r);
        }

        if ($this->isBlank($r->color)) {
            $update['color'] = match ($r->category_name) {
                'Television'      => 'Black',
                default           => (stripos($r->product_name ?? '', 'soundbar') !== false)
                    ? 'Black'
                    : 'White',
            };
        }

        if ($update) {
            $update['updated_at'] = now();
            DB::table('appliance_details')->where('product_id', $r->product_id)->update($update);
        }
    }

    /** Real manufacturer code from product_code, else the model token in the name. */
    private function modelCodeFor(object $r): ?string
    {
        $code = $r->product_code ?? '';
        if ($code !== '' && stripos($code, 'PROD-') !== 0) {
            return $code;
        }

        // Last whitespace-delimited token containing a digit, e.g. "32D3400", "S45H".
        $tokens = preg_split('/\s+/', trim($r->product_name ?? ''));
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (preg_match('/\d/', $tokens[$i])) {
                return $tokens[$i];
            }
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // installation_material_details
    // -----------------------------------------------------------------------

    private function fillConsumableRow(object $r): void
    {
        $detail = DB::table('installation_material_details')->where('product_id', $r->product_id)->first();
        if (! $detail) {
            return;
        }

        $update = [];
        $name   = strtolower($r->product_name ?? '');
        $isSingle = str_contains($name, '(per foot)') || str_contains($name, '(per piece)');

        // Package sizing only for items genuinely sold in packages.
        if (! $isSingle) {
            if ($detail->pieces_per_package === null) {
                $update['pieces_per_package'] = $this->rngInt((int) $r->product_id, 'ppp', 10, 100);
            }
            if ($this->isBlank($detail->package_unit)) {
                $update['package_unit'] = str_contains($name, 'copper tube') ? 'Box' : 'Pack';
            }
        }

        // Modest warranty on AC Parts that plausibly carry one; leave others at 0.
        if ((int) $detail->warranty_value <= 0 && $r->category_name === 'AC Parts') {
            $update['warranty_value'] = 3;
            $update['warranty_unit']  = 'month';
        }

        if ($update) {
            $update['updated_at'] = now();
            DB::table('installation_material_details')->where('product_id', $r->product_id)->update($update);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /** Deterministic pseudo-random int in [$min, $max] from an id + salt. */
    private function rngInt(int $seed, string $salt, int $min, int $max): int
    {
        $h = crc32($seed . ':' . $salt);
        return $min + ($h % ($max - $min + 1));
    }

    /** Deterministic pseudo-random float in [$min, $max]. */
    private function rngFloat(int $seed, string $salt, float $min, float $max): float
    {
        $h = crc32($seed . ':' . $salt);
        return $min + ($h % 10000) / 10000 * ($max - $min);
    }

    /** Deterministic, unique, valid EAN-13 barcode for a product id. */
    private function ean13(int $productId): string
    {
        $body = '480' . str_pad((string) $productId, 9, '0', STR_PAD_LEFT); // 12 digits
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $n = (int) $body[$i];
            $sum += ($i % 2 === 0) ? $n : $n * 3;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $body . $check;
    }

    private function nullCounts(): array
    {
        $p = DB::table('products')->whereNull('deleted_at')->selectRaw("
            SUM(barcode IS NULL OR barcode='') barcode_null,
            SUM(description IS NULL OR description='') desc_null,
            SUM(unit_price=0) price_zero,
            SUM(cost_price=0) cost_zero,
            SUM(recommended_stocks=0) rec_zero,
            SUM(safety_stock IS NULL) ss_null,
            SUM(max_stock_level IS NULL) msl_null
        ")->first();

        $a = DB::table('appliance_details')->selectRaw("
            SUM(model_code IS NULL OR model_code='') model_null,
            SUM(color IS NULL OR color='') color_null
        ")->first();

        $c = DB::table('installation_material_details')->selectRaw("
            SUM(pieces_per_package IS NULL) ppp_null,
            SUM(package_unit IS NULL OR package_unit='') pu_null,
            SUM(warranty_value=0) warr_zero
        ")->first();

        return ['p' => (array) $p, 'a' => (array) $a, 'c' => (array) $c];
    }

    private function report(array $before, array $after): void
    {
        $this->command?->info('DemoProductDataSeeder — empty-field counts (before → after):');
        foreach (['p' => 'products', 'a' => 'appliance_details', 'c' => 'installation_material_details'] as $k => $label) {
            $this->command?->line("  [{$label}]");
            foreach ($before[$k] as $field => $b) {
                $a = $after[$k][$field];
                $this->command?->line(sprintf('    %-14s %5d → %-5d', $field, (int) $b, (int) $a));
            }
        }
    }
}
