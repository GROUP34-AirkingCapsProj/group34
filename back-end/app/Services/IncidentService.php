<?php

namespace App\Services;

use App\Models\IncidentReport;
use Illuminate\Support\Facades\DB;

/**
 * Incident reports are the only legal way to break FIFO order. Filing one
 * excludes the affected batch immediately so picking can continue; the
 * downstream adjustment or correction is posted later, on review.
 *
 * Exclusion is derived from open incidents rather than a flag column on
 * inventory_batches, so resolving or dismissing an incident returns the stock
 * to rotation with no cleanup step.
 */
class IncidentService
{
    public function file(array $data, int $reportedBy): IncidentReport
    {
        if (! in_array($data['reason'] ?? null, IncidentReport::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown incident reason: ' . ($data['reason'] ?? 'null'));
        }

        return DB::transaction(fn () => IncidentReport::create([
            'incident_number' => $this->nextNumber(),
            'source_type'     => $data['source_type'],
            'source_id'       => (int) $data['source_id'],
            'location_id'     => (int) $data['location_id'],
            'product_id'      => (int) $data['product_id'],
            'batch_id'        => $data['batch_id'] ?? null,
            'serial_id'       => $data['serial_id'] ?? null,
            'expected_zone'   => $data['expected_zone'] ?? null,
            'actual_zone'     => $data['actual_zone'] ?? null,
            'reason'          => $data['reason'],
            'quantity'        => (int) ($data['quantity'] ?? 0),
            'narrative'       => $data['narrative'] ?? null,
            'photo_path'      => $data['photo_path'] ?? null,
            'reported_by'     => $reportedBy,
            'status_id'       => IncidentReport::statusId('Open'),
        ]));
    }

    /** Batch ids at a location currently held out of FIFO by an open incident. */
    public function excludedBatchIds(int $locationId): array
    {
        return IncidentReport::open()
            ->where('location_id', $locationId)
            ->whereNotNull('batch_id')
            ->pluck('batch_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** How many incidents a picker has filed in the trailing window — the theft signal. */
    public function incidentCountFor(int $userId, int $days = 30): int
    {
        return IncidentReport::where('reported_by', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    private function nextNumber(): string
    {
        return 'INC-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    }

    /**
     * Resolve an open incident. Each reason produces a different artifact:
     *   not_found / damaged  → deduction Adjustment, stock permanently out
     *   wrong_type           → ScanCorrection, batch re-typed to the real product
     *   zone_mismatch        → zone_label updated, no quantity movement
     * Dismissing produces nothing and returns the stock to FIFO rotation.
     */
    public function resolve(IncidentReport $incident, string $action, array $options, int $reviewedBy): IncidentReport
    {
        // Input guards that don't touch the DB — cheap to fail fast, re-checked
        // under the lock below where it matters.
        if ($action === 'confirm'
            && $incident->reason === IncidentReport::REASON_WRONG_TYPE
            && empty($options['corrected_product_id'])) {
            throw new \InvalidArgumentException('A corrected product is required to resolve a wrong-type incident.');
        }

        if ($action === 'confirm'
            && $incident->reason === IncidentReport::REASON_WRONG_TYPE
            && $incident->batch_id === null) {
            // wrong_type means the batch was received as the wrong product; with no
            // batch there is nothing to re-type, so closing it would leave the real
            // stock wrong while reading as fixed.
            throw new \InvalidArgumentException('A wrong-type incident must reference a batch to re-type.');
        }

        return DB::transaction(function () use ($incident, $action, $options, $reviewedBy) {
            // Lock the incident row and re-check Open UNDER the lock, so two
            // reviewers resolving at once cannot both pass the guard and
            // double-book the write-off. The plain read outside a transaction
            // would let both through.
            $locked = IncidentReport::whereKey($incident->getKey())->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== IncidentReport::statusId('Open')) {
                throw new \RuntimeException('This incident has already been reviewed.');
            }

            if ($action === 'dismiss') {
                $locked->update([
                    'status_id'       => IncidentReport::statusId('Dismissed'),
                    'reviewed_by'     => $reviewedBy,
                    'reviewed_at'     => now(),
                    'resolution_note' => $options['resolution_note'] ?? null,
                ]);

                return $locked->fresh();
            }

            $adjustmentId = null;
            $correctionId = null;

            switch ($locked->reason) {
                case IncidentReport::REASON_NOT_FOUND:
                case IncidentReport::REASON_DAMAGED:
                    $adjustmentId = $this->postDeduction($locked, $reviewedBy);
                    break;

                case IncidentReport::REASON_WRONG_TYPE:
                    $correctionId = $this->postCorrection($locked, (int) $options['corrected_product_id'], $reviewedBy);
                    break;

                case IncidentReport::REASON_ZONE_MISMATCH:
                    $this->relocate($locked);
                    break;
            }

            $locked->update([
                'status_id'       => IncidentReport::statusId('Resolved'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $adjustmentId,
                'correction_id'   => $correctionId,
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /** Write the stock off the batch and the location inventory, under an Adjustment. */
    private function postDeduction(IncidentReport $incident, int $reviewedBy): int
    {
        $requested = max(0, (int) $incident->quantity);

        $batch = $incident->batch_id !== null
            ? \App\Models\InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $inventory = \App\Models\Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        // The real write-off cannot exceed what the batch actually holds (or, with
        // no batch, what the location holds). Compute ONE deducted amount and use
        // it for the ledger, the batch, and the location total alike — the old code
        // recorded the full requested quantity while clamping the batch and the
        // inventory independently, so a request larger than the batch's remaining
        // vanished units from the location total that no batch ever lost.
        $available = $batch !== null
            ? (int) $batch->quantity_remaining
            : (int) ($inventory->quantity_on_hand ?? $requested);
        $deducted = min($requested, $available);

        $adjustment = \App\Models\Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $incident->reason === IncidentReport::REASON_DAMAGED ? 'damage' : 'loss',
            'total_variance_positive' => 0,
            'total_variance_negative' => $deducted,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => $deducted,
            'reason'          => $incident->reason,
        ]);

        if ($batch !== null) {
            $batch->decrement('quantity_remaining', $deducted);
        }

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($deducted, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($deducted, (int) $inventory->available_quantity));
        }

        return (int) $adjustment->adjustment_id;
    }

    /** Re-type the batch to the product physically on the shelf. */
    private function postCorrection(IncidentReport $incident, int $correctedProductId, int $reviewedBy): int
    {
        // Locked to match postDeduction's discipline — the re-type and the paired
        // inventory move must see and hold a consistent batch row.
        $batch = $incident->batch_id !== null
            ? \App\Models\InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $correction = \App\Models\ScanCorrection::create([
            'receiving_id'         => $batch?->receiving_id,
            'original_product_id'  => (int) $incident->product_id,
            'corrected_product_id' => $correctedProductId,
            'correction_type'      => 'wrong_product',
            'quantity_before'      => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'quantity_after'       => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'serial_id'            => $incident->serial_id,
            'reason'               => "Incident {$incident->incident_number}: {$incident->narrative}",
            'corrected_by'         => (int) $incident->reported_by,
            'approved_by'          => $reviewedBy,
        ]);

        if ($batch !== null) {
            $quantity = (int) $batch->quantity_remaining;

            $batch->update(['product_id' => $correctedProductId]);

            $from = \App\Models\Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)->lockForUpdate()->first();
            if ($from !== null) {
                $from->decrement('quantity_on_hand', min($quantity, (int) $from->quantity_on_hand));
                $from->decrement('available_quantity', min($quantity, (int) $from->available_quantity));
            }

            $to = \App\Models\Inventory::firstOrCreate(
                ['location_id' => (int) $incident->location_id, 'product_id' => $correctedProductId],
                ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
            );
            $to->increment('quantity_on_hand', $quantity);
            $to->increment('available_quantity', $quantity);
        }

        return (int) $correction->correction_id;
    }

    /** Stock exists, it is simply somewhere else. No quantity moves. */
    private function relocate(IncidentReport $incident): void
    {
        if ($incident->batch_id === null || $incident->actual_zone === null) {
            return;
        }

        \App\Models\InventoryBatch::where('batch_id', $incident->batch_id)
            ->update(['zone_label' => $incident->actual_zone]);
    }
}
