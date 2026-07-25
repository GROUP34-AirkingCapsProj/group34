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
}
