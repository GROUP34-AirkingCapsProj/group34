<?php

namespace Tests\Feature\Transfer;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferIntentSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_transfers_table_has_intent_and_nullable_source(): void
    {
        $this->assertTrue(Schema::hasColumn('transfers', 'intent'));

        $col = collect(Schema::getColumns('transfers'))->firstWhere('name', 'from_location_id');
        $this->assertTrue((bool) $col['nullable'], 'from_location_id must be nullable');
    }
}
