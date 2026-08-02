<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strips the lookup tables to the columns that specify a product.
     *
     * sort_order was added on the reasoning that alphabetical ordering puts
     * 10HP before 2HP. The problem is real; hand-numbering every row is not
     * the answer, and the data agreed — zero non-default values on either
     * table. category_id scoped capacity and variant per category, which in
     * practice hid values rather than organising them.
     */
    public function up(): void
    {
        Schema::table('capacity_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('capacity_lookup', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
            if (Schema::hasColumn('capacity_lookup', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('capacity_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('variant_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('variant_lookup', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
            if (Schema::hasColumn('variant_lookup', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('variant_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('category_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('category_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('brand_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('brand_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    /**
     * Restores the columns but NOT their contents — the descriptions and
     * category assignments are gone the moment up() runs. This exists so a
     * rollback does not leave the migrations table claiming a state the
     * database is not in. To recover data, re-import the mysqldump taken
     * before up() was run.
     */
    public function down(): void
    {
        Schema::table('capacity_lookup', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('capacity_value');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreign('category_id')->references('category_id')->on('category_lookup')->nullOnDelete();
        });

        Schema::table('variant_lookup', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('variant_name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreign('category_id')->references('category_id')->on('category_lookup')->nullOnDelete();
        });

        Schema::table('category_lookup', function (Blueprint $table) {
            $table->text('description')->nullable();
        });

        Schema::table('brand_lookup', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }
};
