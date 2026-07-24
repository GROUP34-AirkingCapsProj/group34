<?php

namespace Tests\Feature\Sets;

use Illuminate\Support\Facades\Schema;

class SetSchemaTest extends SetTestCase
{
    public function test_product_set_tables_exist_with_matching_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_sets'));
        $this->assertTrue(Schema::hasTable('product_set_lines'));

        foreach (['set_code', 'set_name', 'brand_id', 'capacity_rating', 'variant', 'is_active', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_sets', $col), "product_sets missing {$col}");
        }
        foreach (['set_id', 'product_id', 'quantity', 'issuable_unit_id', 'spec_note', 'sort_order'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_set_lines', $col), "product_set_lines missing {$col}");
        }
    }
}
