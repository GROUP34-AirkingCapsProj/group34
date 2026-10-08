<?php

namespace Tests\Feature\Budget;

class VerificationExportTest extends BudgetTestCase
{
    public function test_po_export_includes_verification_columns(): void
    {
        $admin = $this->makeUser('admin');
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['verification_status' => 'verified', 'verified_by' => $auditor->user_id, 'verified_at' => now()]);

        $res = $this->actingAs($admin, 'sanctum')->get('/api/purchase-orders/export');
        $res->assertSuccessful();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('Verified By', $csv);
        $this->assertStringContainsString('Verification Status', $csv);
        $this->assertStringContainsString('verified', $csv);
    }
}
