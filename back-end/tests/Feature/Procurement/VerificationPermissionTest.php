<?php

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

class VerificationPermissionTest extends ProcurementTestCase
{
    /**
     * An auditor's write access is limited to verification. Any other write
     * endpoint — here supplier archiving, which only admin holds — must 403
     * even though the auditor can read suppliers.
     */
    public function test_auditor_cannot_write_non_verification_endpoint(): void
    {
        $auditor = $this->makeUser('auditor');

        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Auditor Guard Supplier ' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'supplier_id');

        $this->actingAs($auditor, 'sanctum')
            ->deleteJson("/api/suppliers/{$supplierId}")
            ->assertStatus(403);
    }
}
