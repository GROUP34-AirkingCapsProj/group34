<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Services\Incidents\RelocateResolution;
use App\Services\Incidents\ResolvesIncident;
use App\Services\Incidents\RetypeResolution;
use App\Services\Incidents\WriteOffResolution;
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
     * Resolve an open incident. The artifact depends on the reason and is
     * produced by a strategy; this method owns only what must never be
     * duplicated — the transaction, the row lock, the still-Open re-check, and
     * the status stamping.
     *
     * Dismissing produces nothing and returns the stock to FIFO rotation.
     */
    public function resolve(IncidentReport $incident, string $action, array $options, int $reviewedBy): IncidentReport
    {
        $strategy = $this->strategyFor($incident->reason);

        // Cheap pre-flight, outside the transaction. Re-checked under the lock
        // below where it actually matters.
        if ($action === 'confirm') {
            $strategy->validate($incident, $options);
        }

        return DB::transaction(function () use ($incident, $action, $options, $reviewedBy, $strategy) {
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

            $strategy->validate($locked, $options);
            $posted = $strategy->apply($locked, $options, $reviewedBy);

            $locked->update([
                'status_id'       => IncidentReport::statusId('Resolved'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $posted['adjustment_id'],
                'correction_id'   => $posted['correction_id'],
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * No default arm: `match` throws UnhandledMatchError if a reason is added
     * without a strategy, which is exactly the failure we want — loud, at the
     * first resolve, rather than an incident silently closing with no artifact.
     */
    private function strategyFor(string $reason): ResolvesIncident
    {
        return match ($reason) {
            IncidentReport::REASON_NOT_FOUND,
            IncidentReport::REASON_DAMAGED       => new WriteOffResolution(),
            IncidentReport::REASON_WRONG_TYPE    => new RetypeResolution(),
            IncidentReport::REASON_ZONE_MISMATCH => new RelocateResolution(),
        };
    }
}
