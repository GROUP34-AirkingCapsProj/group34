<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a composite index on products(created_at, product_id) to back the
 * default product-list ordering `ORDER BY created_at DESC, product_id DESC`
 * used by ProductController@index. Without it, MySQL does a full filesort on
 * every page load, which gets slow once the products table grows large.
 *
 * Note: the list's text search uses leading-wildcard LIKE ('%term%'), which
 * no B-tree index can accelerate — so no indexes are added for those columns.
 * A FULLTEXT index would be the way to speed that up, but that requires
 * changing the query to MATCH...AGAINST and is intentionally out of scope here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! $this->indexExists('products', 'products_created_at_product_id_idx')) {
                $table->index(['created_at', 'product_id'], 'products_created_at_product_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if ($this->indexExists('products', 'products_created_at_product_id_idx')) {
                $table->dropIndex('products_created_at_product_id_idx');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(\DB::select(
            'SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?',
            [$index]
        )) > 0;
    }
};
