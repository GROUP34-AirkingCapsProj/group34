<?php

namespace Tests\Feature\Issuance;

use App\Support\IssuanceStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancelIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cancelled_issuance_status_exists_and_resolves(): void
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->value('status_id');

        $this->assertNotNull($id, 'Cancelled issuance status is missing');
        $this->assertSame((int) $id, IssuanceStatus::cancelled());
    }
}
