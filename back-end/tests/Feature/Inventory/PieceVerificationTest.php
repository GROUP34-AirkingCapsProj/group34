<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PieceVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_verification_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('receiving_details', 'packages_received'));
        $this->assertTrue(Schema::hasColumn('receiving_details', 'pieces_expected'));
    }
}
