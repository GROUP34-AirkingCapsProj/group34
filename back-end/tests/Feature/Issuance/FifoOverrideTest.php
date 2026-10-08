<?php

namespace Tests\Feature\Issuance;

use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\User;
use App\Support\IssuanceStatus;
use App\Support\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Sets\SetTestCase;

/**
 * The permissioned FIFO override at issuance: a picker holding
 * inventory_issuance.fifo_override may accept a FIFO violation by supplying a
 * written reason, which is persisted on the issued line.
 */
class FifoOverrideTest extends SetTestCase
{
    private const SCAN_URL = '/api/issuance-scan/scan';

    private function confirmedOrder($loc, array $lines): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::pending(),
        ]);

        foreach ($lines as [$product, $qty]) {
            $issuance->details()->create([
                'product_id'         => $product->product_id,
                'quantity_requested' => $qty,
                'quantity_issued'    => 0,
                'unit_price'         => (float) $product->unit_price,
                'subtotal'           => $qty * (float) $product->unit_price,
            ]);
        }

        return $issuance->fresh('details');
    }

    /** Two batches of one product: Zone 1 old (the FIFO pick), Zone 2 new. */
    private function twoBatchScenario(string $barcode): array
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeInstallationMaterial(barcode: $barcode);
        $this->setStock($loc->location_id, $p->product_id, 20);
        // Distinct remaining quantities so a wrong-batch deduction cannot hide
        // behind two rows that happen to look alike.
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 7, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $new = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        return [$loc, $user, $p, $this->confirmedOrder($loc, [[$p, 2]]), $old, $new];
    }

    private function permissionId(): ?int
    {
        return DB::table('permissions')
            ->where('feature', 'inventory_issuance')
            ->where('action', 'fifo_override')
            ->value('permission_id');
    }

    private function setOverrideFor(User $user, bool $granted): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id'       => $user->user_id,
            'permission_id' => $this->permissionId(),
            'is_granted'    => $granted,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function issuedQty(Issuance $issuance): int
    {
        return (int) IssuanceDetail::where('issuance_id', $issuance->issuance_id)->sum('quantity_issued');
    }

    public function test_schema_and_permission_exist(): void
    {
        $this->assertTrue(
            Schema::hasColumn('issuance_details', 'fifo_override_reason'),
            'issuance_details.fifo_override_reason is missing - did the migration reach the TEST database?'
        );

        $this->assertNotNull($this->permissionId(), 'inventory_issuance.fifo_override permission row is missing');
    }

    public function test_fifo_violation_without_override_still_rejects(): void
    {
        [, $user, , $issuance] = $this->twoBatchScenario('BC-OV-PLAIN');

        $res = $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'    => $issuance->issuance_id,
            'barcode'        => 'BC-OV-PLAIN',
            'quantity'       => 1,
            'confirmed_zone' => 'Zone 2',
        ]);

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertSame(0, $this->issuedQty($issuance));
    }

    public function test_override_without_the_permission_is_refused(): void
    {
        [, $user, , $issuance] = $this->twoBatchScenario('BC-OV-NOPERM');

        // warehouse_personnel holds inventory_issuance.update, so the migration
        // grants it fifo_override by default; revoke it with a per-user override
        // so this user is genuinely unprivileged for THIS permission while still
        // being allowed to scan at all. Admin is excluded by construction: the
        // resolver bypasses every check for the admin role.
        $this->setOverrideFor($user, false);

        $this->assertFalse(
            app(PermissionResolver::class)->canUser($user->user_id, 'inventory_issuance', 'fifo_override'),
            'fixture is vacuous: the user still holds fifo_override'
        );

        $res = $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-NOPERM',
            'quantity'             => 1,
            'confirmed_zone'       => 'Zone 2',
            'accept_fifo_override' => true,
            'override_reason'      => 'Front pallet is blocked by a delivery truck',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('permission', (string) $res->json('reason'));
        $this->assertSame(0, $this->issuedQty($issuance));
    }

    public function test_too_short_reason_is_refused(): void
    {
        [, $user, , $issuance] = $this->twoBatchScenario('BC-OV-SHORT');

        $this->setOverrideFor($user, true);
        $this->assertTrue(
            app(PermissionResolver::class)->canUser($user->user_id, 'inventory_issuance', 'fifo_override')
        );

        $res = $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-SHORT',
            'quantity'             => 1,
            'confirmed_zone'       => 'Zone 2',
            'accept_fifo_override' => true,
            'override_reason'      => 'damaged',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, $this->issuedQty($issuance));
    }

    public function test_override_with_permission_and_reason_succeeds_and_persists_the_reason(): void
    {
        [, $user, $p, $issuance] = $this->twoBatchScenario('BC-OV-OK');

        $this->setOverrideFor($user, true);

        $reason = 'Zone 1 pallet is behind a quarantined stack; picked Zone 2';

        $res = $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-OK',
            'quantity'             => 1,
            'confirmed_zone'       => 'Zone 2',
            'accept_fifo_override' => true,
            'override_reason'      => $reason,
        ]);

        $res->assertOk();
        $this->assertGreaterThan(0, $this->issuedQty($issuance));

        // Asserted from the database, not the response body.
        $stored = DB::table('issuance_details')
            ->where('issuance_id', $issuance->issuance_id)
            ->where('product_id', $p->product_id)
            ->where('quantity_issued', '>', 0)
            ->value('fifo_override_reason');

        $this->assertSame($reason, $stored);
    }

    public function test_wrong_product_is_not_overridable(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');
        $onList  = $this->makeInstallationMaterial();
        $offList = $this->makeInstallationMaterial(barcode: 'BC-OV-OFFLIST');
        $this->setStock($loc->location_id, $onList->product_id, 10);
        $this->setStock($loc->location_id, $offList->product_id, 10);
        $this->makeBatch($offList, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $this->setOverrideFor($user, true);

        $issuance = $this->confirmedOrder($loc, [[$onList, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-OFFLIST',
            'quantity'             => 1,
            'accept_fifo_override' => true,
            'override_reason'      => 'Supervisor authorised this substitution today',
        ]);

        $res->assertStatus(422);
        $this->assertSame('WRONG_PRODUCT', $res->json('scan_result.status'));
        $this->assertSame(0, $this->issuedQty($issuance));
    }

    /**
     * The bug this guards: deductFifo() drains oldest-first regardless of what
     * was scanned, so an authorised override used to take the picker's Zone 2
     * goods off Zone 1's books - overstating Zone 1 as depleted while its stock
     * sat untouched, and poisoning every later FIFO decision for the product.
     */
    public function test_override_deducts_from_the_scanned_batch_not_the_oldest(): void
    {
        [, $user, , $issuance, $oldBatch, $newBatch] = $this->twoBatchScenario('BC-OV-LEDGER');

        $this->setOverrideFor($user, true);

        $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-LEDGER',
            'quantity'             => 2,
            'confirmed_zone'       => 'Zone 2',
            'accept_fifo_override' => true,
            'override_reason'      => 'Zone 1 aisle is blocked by a pallet jack today',
        ])->assertOk();

        $remaining = fn ($batch) => (int) DB::table('inventory_batches')
            ->where('batch_id', $batch->batch_id)
            ->value('quantity_remaining');

        $this->assertSame(3, $remaining($newBatch), 'the scanned (newer) batch should have been deducted');
        $this->assertSame(7, $remaining($oldBatch), 'the oldest batch must be left untouched by an override');
    }

    public function test_override_line_records_the_scanned_batch(): void
    {
        [, $user, $p, $issuance, $oldBatch, $newBatch] = $this->twoBatchScenario('BC-OV-BATCHID');

        $this->setOverrideFor($user, true);

        $this->actingAs($user, 'sanctum')->postJson(self::SCAN_URL, [
            'issuance_id'          => $issuance->issuance_id,
            'barcode'              => 'BC-OV-BATCHID',
            'quantity'             => 1,
            'confirmed_zone'       => 'Zone 2',
            'accept_fifo_override' => true,
            'override_reason'      => 'Customer collecting now; Zone 1 is behind the transfer cage',
        ])->assertOk();

        $line = DB::table('issuance_details')
            ->where('issuance_id', $issuance->issuance_id)
            ->where('product_id', $p->product_id)
            ->where('quantity_issued', '>', 0)
            ->first();

        $this->assertSame((int) $newBatch->batch_id, (int) $line->batch_id);
        $this->assertNotSame((int) $oldBatch->batch_id, (int) $line->batch_id);
        $this->assertSame('Zone 2', $line->zone_label);
    }
}
