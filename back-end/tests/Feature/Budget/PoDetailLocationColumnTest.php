<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrderDetail;
use Illuminate\Support\Facades\Schema;

class PoDetailLocationColumnTest extends BudgetTestCase
{
    public function test_location_id_column_and_relation_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('purchase_order_details', 'location_id'));
        $this->assertTrue(method_exists(PurchaseOrderDetail::class, 'location'));
    }
}
