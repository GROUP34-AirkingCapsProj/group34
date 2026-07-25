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
        if ((int) $incident->status_id !== IncidentReport::statusId('Open')) {
            throw new \RuntimeException('This incident has already been reviewed.');
        }

        if ($action === 'dismiss') {
            $incident->update([
                'status_id'       => IncidentReport::statusId('Dismissed'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $incident->fresh();
        }

        if ($incident->reason === IncidentReport::REASON_WRONG_TYPE
            && empty($options['corrected_product_id'])) {
            throw new \InvalidArgumentException('A corrected product is required to resolve a wrong-type incident.');
        }

        return DB::transaction(function () use ($incident, $options, $reviewedBy) {
            $adjustmentId = null;
            $correctionId = null;

            switch ($incident->reason) {
                case IncidentReport::REASON_NOT_FOUND:
                case IncidentReport::REASON_DAMAGED:
                    $adjustmentId = $this->postDeduction($incident, $reviewedBy);
                    break;

                case IncidentReport::REASON_WRONG_TYPE:
                    $correctionId = $this->postCorrection($incident, (int) $options['corrected_product_id'], $reviewedBy);
                    break;

                case IncidentReport::REASON_ZONE_MISMATCH:
                    $this->relocate($incident);
                    break;
            }

            $incident->update([
                'status_id'       => IncidentReport::statusId('Resolved'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $adjustmentId,
                'correction_id'   => $correctionId,
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $incident->fresh();
        });
    }

    /** Write the stock off the batch and the location inventory, under an Adjustment. */
    private function postDeduction(IncidentReport $incident, int $reviewedBy): int
    {
        $quantity = max(0, (int) $incident->quantity);

        $adjustment = \App\Models\Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $incident->reason === IncidentReport::REASON_DAMAGED ? 'damage' : 'loss',
            'total_variance_positive' => 0,
            'total_variance_negative' => $quantity,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => $quantity,
            'reason'          => $incident->reason,
        ]);

        if ($incident->batch_id !== null) {
            $batch = \App\Models\InventoryBatch::lockForUpdate()->find($incident->batch_id);
            if ($batch !== null) {
                $batch->decrement('quantity_remaining', min($quantity, (int) $batch->quantity_remaining));
            }
        }

        $inventory = \App\Models\Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($quantity, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($quantity, (int) $inventory->available_quantity));
        }

        return (int) $adjustment->adjustment_id;
    }

    /** Re-type the batch to the product physically on the shelf. */
    private function postCorrection(IncidentReport $incident, int $correctedProductId, int $reviewedBy): int
    {
        $batch = $incident->batch_id !== null
            ? \App\Models\InventoryBatch::find($incident->batch_id)
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
