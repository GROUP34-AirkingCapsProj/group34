<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('product_set_brands')) {
            Schema::create('product_set_brands', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('set_id');
                $t->unsignedBigInteger('brand_id');
                $t->timestamps();
                $t->unique(['set_id', 'brand_id']);
                $t->foreign('set_id')->references('set_id')->on('product_sets')->cascadeOnDelete();
                $t->foreign('brand_id')->references('brand_id')->on('brand_lookup')->cascadeOnDelete();
            });
        }

        // Migrate any existing single brand_id into the pivot before dropping it.
        if (Schema::hasColumn('product_sets', 'brand_id')) {
            $rows = DB::table('product_sets')->whereNotNull('brand_id')->get(['set_id', 'brand_id']);
            foreach ($rows as $r) {
                DB::table('product_set_brands')->updateOrInsert(
                    ['set_id' => $r->set_id, 'brand_id' => $r->brand_id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // The brand_id FK relies on product_sets_match_idx, so the FK must go first.
        Schema::table('product_sets', function (Blueprint $t) {
            if (Schema::hasColumn('product_sets', 'brand_id')) {
                try { $t->dropForeign(['brand_id']); } catch (\Throwable $e) {}
            }
        });
        Schema::table('product_sets', function (Blueprint $t) {
            try { $t->dropIndex('product_sets_match_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('product_sets', function (Blueprint $t) {
            if (Schema::hasColumn('product_sets', 'brand_id')) {
                $t->dropColumn('brand_id');
            }
            if (Schema::hasColumn('product_sets', 'set_code'))    { try { $t->dropUnique('product_sets_set_code_unique'); } catch (\Throwable $e) {} $t->dropColumn('set_code'); }
            if (Schema::hasColumn('product_sets', 'set_name'))    $t->dropColumn('set_name');
            if (Schema::hasColumn('product_sets', 'description')) $t->dropColumn('description');
        });

        Schema::table('product_set_lines', function (Blueprint $t) {
            if (Schema::hasColumn('product_set_lines', 'issuable_unit_id')) {
                try { $t->dropForeign(['issuable_unit_id']); } catch (\Throwable $e) {}
                $t->dropColumn('issuable_unit_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_sets', function (Blueprint $t) {
            if (! Schema::hasColumn('product_sets', 'set_code')) $t->string('set_code', 60)->nullable();
            if (! Schema::hasColumn('product_sets', 'set_name')) $t->string('set_name', 191)->nullable();
        });
        Schema::dropIfExists('product_set_brands');
    }
};
