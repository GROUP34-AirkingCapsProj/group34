<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newUnits = [
        ['unit_id' => 12, 'unit_name' => 'Foot',   'unit_abbreviation' => 'ft'],
        ['unit_id' => 13, 'unit_name' => 'Can',    'unit_abbreviation' => 'can'],
        ['unit_id' => 14, 'unit_name' => 'Inch',   'unit_abbreviation' => 'in'],
        ['unit_id' => 15, 'unit_name' => 'Pack',   'unit_abbreviation' => 'pack'],
        ['unit_id' => 16, 'unit_name' => 'Drum',   'unit_abbreviation' => 'drum'],
        ['unit_id' => 17, 'unit_name' => 'Carton', 'unit_abbreviation' => 'ctn'],
        ['unit_id' => 18, 'unit_name' => 'Bag',    'unit_abbreviation' => 'bag'],
    ];

    public function up(): void
    {
        $now = now();

        // 1. Insert missing unit_lookup rows (skip any that already exist)
        foreach ($this->newUnits as $u) {
            $exists = DB::table('unit_lookup')
                ->whereRaw('LOWER(unit_name) = ?', [strtolower($u['unit_name'])])
                ->exists();
            if (! $exists) {
                DB::table('unit_lookup')->insert(array_merge($u, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // Helper: resolve unit_id by name
        $uid = fn (string $name): int => (int) DB::table('unit_lookup')
            ->whereRaw('LOWER(unit_name) = ?', [strtolower($name)])
            ->value('unit_id');

        // Helper: resolve product_id by name (non-deleted consumables)
        $pid = fn (string $name): ?int => ($row = DB::table('products')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(product_name) = ?', [strtolower(trim($name))])
            ->value('product_id')) ? (int) $row : null;

        // 2. Issuable unit definitions per product.
        //    conversion_factor = inventory pieces deducted per 1 of this unit.
        $catalog = [
            // Solvent (unit=Piece, 50 pieces/Box)
            'solvent' => [
                ['label' => '1 Box (50 pieces)',  'unit' => 'Box',       'conversion_factor' => 50,  'is_whole_package' => true,  'sort_order' => 1, 'show_as_quick_action' => true],
                ['label' => '2 Boxes (100 pcs)',   'unit' => 'Box',       'conversion_factor' => 100, 'is_whole_package' => true,  'sort_order' => 2, 'show_as_quick_action' => true],
                ['label' => '1 Piece',             'unit' => 'Piece',     'conversion_factor' => 1,   'is_whole_package' => false, 'sort_order' => 3, 'show_as_quick_action' => true],
            ],
            // Copper Tube (unit=Roll, 25 rolls/box)
            'copper tube' => [
                ['label' => '1 Box (25 rolls)',   'unit' => 'Box',        'conversion_factor' => 25,  'is_whole_package' => true,  'sort_order' => 1, 'show_as_quick_action' => true],
                ['label' => '1 Roll',             'unit' => 'Roll',       'conversion_factor' => 1,   'is_whole_package' => false, 'sort_order' => 2, 'show_as_quick_action' => true],
                ['label' => 'Half Roll',          'unit' => 'Half Roll',  'conversion_factor' => 12,  'is_whole_package' => false, 'sort_order' => 3, 'show_as_quick_action' => true],
                ['label' => 'Quarter Roll',       'unit' => 'Quarter Roll','conversion_factor' => 6,  'is_whole_package' => false, 'sort_order' => 4, 'show_as_quick_action' => true],
                ['label' => '1 Foot',             'unit' => 'Foot',       'conversion_factor' => 1,   'is_whole_package' => false, 'sort_order' => 5, 'show_as_quick_action' => false],
            ],
        ];

        foreach ($catalog as $productName => $units) {
            $productId = $pid($productName);
            if ($productId === null) {
                continue;
            }
            foreach ($units as $def) {
                $unitId = $uid($def['unit']);
                $exists = DB::table('product_issuable_units')
                    ->where('product_id', $productId)
                    ->where('label', $def['label'])
                    ->exists();
                if (! $exists) {
                    DB::table('product_issuable_units')->insert([
                        'product_id'           => $productId,
                        'unit_id'              => $unitId,
                        'label'                => $def['label'],
                        'conversion_factor'    => $def['conversion_factor'],
                        'is_whole_package'     => $def['is_whole_package'] ? 1 : 0,
                        'sort_order'           => $def['sort_order'],
                        'show_as_quick_action' => $def['show_as_quick_action'] ? 1 : 0,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('product_issuable_units')
            ->whereIn('product_id', function ($q) {
                $q->select('product_id')->from('products')
                  ->whereRaw("LOWER(product_name) IN ('solvent','copper tube')")
                  ->whereNull('deleted_at');
            })
            ->delete();

        foreach ($this->newUnits as $u) {
            DB::table('unit_lookup')
                ->whereRaw('LOWER(unit_name) = ?', [strtolower($u['unit_name'])])
                ->delete();
        }
    }
};
