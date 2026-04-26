<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Http\Controllers\Api\ReceivingController;
use App\Http\Controllers\Api\IssuanceController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\AdjustmentController;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:core-check {--commit : Persist changes (default is rollback dry-run)}', function () {
    $commit = (bool) $this->option('commit');

    $this->info('Running core inventory transaction check...');
    $this->line($commit ? 'Mode: COMMIT' : 'Mode: DRY RUN (rollback)');

    // Choose a product+source inventory row (stock can be zero; receive step will add stock first).
    $sourceInv = Inventory::query()
        ->whereNotNull('location_id')
        ->whereNotNull('product_id')
        ->orderByDesc('available_quantity')
        ->orderByDesc('quantity_on_hand')
        ->first();

    $product = null;
    $sourceLocationId = null;
    if ($sourceInv) {
        $product = Product::find($sourceInv->product_id);
        $sourceLocationId = $sourceInv->location_id;
    }

    if (!$product || !$sourceLocationId) {
        $product = Product::query()->first();
        $firstLocation = Location::query()->first();
        if (!$product || !$firstLocation) {
            $this->error('Cannot run check: need at least one product and one location.');
            return 1;
        }
        $sourceLocationId = $firstLocation->location_id;
    }
    $destLocation = Location::query()->where('location_id', '!=', $sourceLocationId)->first();
    if (!$destLocation) {
        $this->error('No destination location found. Need at least 2 locations.');
        return 1;
    }

    $po = PurchaseOrder::query()->whereNotNull('po_id')->first();

    $destLocationId = $destLocation->location_id;

    $getQty = function (int|string $locationId, int|string $productId): int {
        $row = Inventory::query()
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->first();
        return (int) ($row?->quantity_on_hand ?? 0);
    };

    $beforeSource = $getQty($sourceLocationId, $product->product_id);
    $beforeDest = $getQty($destLocationId, $product->product_id);
    $stepReceive = 3;
    $stepIssue = 1;
    $stepTransfer = 1;
    $stepAdjust = 1;

    DB::beginTransaction();
    try {
        $suffix = now()->format('YmdHis') . '-' . random_int(100, 999);
        $assertOk = function ($response, string $label) {
            $status = method_exists($response, 'status') ? $response->status() : 500;
            $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
            if ($status >= 400 || (isset($payload['success']) && $payload['success'] === false)) {
                $msg = $payload['message'] ?? "{$label} failed";
                throw new \RuntimeException("{$label} failed: {$msg}");
            }
        };

        if (!$po) {
            $poNumberCol = Schema::hasColumn('purchase_orders', 'pc_number') ? 'pc_number' : 'po_number';
            $poId = DB::table('purchase_orders')->insertGetId([
                'supplier_id' => null,
                'location_id' => $sourceLocationId,
                $poNumberCol => "PO-CHECK-{$suffix}",
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(1)->toDateString(),
                'total_amount' => 0,
                'grand_total' => 0,
                'status_id' => null,
                'created_by' => null,
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $po = PurchaseOrder::query()->find($poId);
        }

        // 1) Receiving: +3 at source
        $receivingReq = Request::create('/api/receivings', 'POST', [
            'pc_id' => $po->po_id,
            'location_id' => $sourceLocationId,
            'receiving_number' => "RCV-CHECK-{$suffix}",
            'receiving_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->product_id,
                'quantity_amount' => $stepReceive,
                'condition' => 'Good',
            ]],
        ]);
        $receivingResp = app(ReceivingController::class)->store($receivingReq);
        $assertOk($receivingResp, 'Receiving');

        // 2) Issuance: -1 at source
        $issuanceReq = Request::create('/api/issuances', 'POST', [
            'location_id' => $sourceLocationId,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'Operations',
            'purpose' => 'Core transaction validation',
            'details' => [[
                'product_id' => $product->product_id,
                'quantity_issued' => $stepIssue,
                'condition_issued' => 'Good',
            ]],
        ]);
        $issuanceResp = app(IssuanceController::class)->store($issuanceReq);
        $assertOk($issuanceResp, 'Issuance');

        // 3) Transfer: -1 source, +1 destination
        $transferReq = Request::create('/api/transfers', 'POST', [
            'from_location_id' => $sourceLocationId,
            'to_location_id' => $destLocationId,
            'transfer_number' => "TR-CHECK-{$suffix}",
            'transfer_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->product_id,
                'quantity_transferred' => $stepTransfer,
            ]],
        ]);
        $transferResp = app(TransferController::class)->store($transferReq);
        $assertOk($transferResp, 'Transfer');

        // 4) Adjustment: deduct 1 at source
        $adjustReq = Request::create('/api/adjustments', 'POST', [
            'location_id' => $sourceLocationId,
            'adjustment_number' => "ADJ-CHECK-{$suffix}",
            'adjustment_date' => now()->toDateString(),
            'adjustment_type' => 'Stock Count',
            'details' => [[
                'product_id' => $product->product_id,
                'add_quantity' => 0,
                'deduct_quantity' => $stepAdjust,
            ]],
        ]);
        $adjustResp = app(AdjustmentController::class)->store($adjustReq);
        $assertOk($adjustResp, 'Adjustment');

        $afterSource = $getQty($sourceLocationId, $product->product_id);
        $afterDest = $getQty($destLocationId, $product->product_id);

        $expectedSource = $beforeSource + $stepReceive - $stepIssue - $stepTransfer - $stepAdjust; // net 0 with defaults
        $expectedDest = $beforeDest + $stepTransfer;

        $okSource = $afterSource === $expectedSource;
        $okDest = $afterDest === $expectedDest;

        $this->newLine();
        $this->table(
            ['Metric', 'Before', 'After', 'Expected', 'Status'],
            [
                ['Source Qty', (string) $beforeSource, (string) $afterSource, (string) $expectedSource, $okSource ? 'OK' : 'MISMATCH'],
                ['Destination Qty', (string) $beforeDest, (string) $afterDest, (string) $expectedDest, $okDest ? 'OK' : 'MISMATCH'],
            ]
        );

        if ($okSource && $okDest) {
            $this->info('Core transaction check passed.');
        } else {
            $this->error('Core transaction check failed (inventory totals mismatch).');
        }

        if ($commit) {
            DB::commit();
            $this->warn('Changes committed.');
        } else {
            DB::rollBack();
            $this->line('Dry-run complete: all changes rolled back.');
        }

        return ($okSource && $okDest) ? 0 : 1;
    } catch (\Throwable $e) {
        DB::rollBack();
        $this->error('Core transaction check failed: ' . $e->getMessage());
        return 1;
    }
})->purpose('Validate receiving, issuance, transfer, adjustment inventory effects');
