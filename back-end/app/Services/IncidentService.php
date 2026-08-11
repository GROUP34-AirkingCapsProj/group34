<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\Incidents\AcknowledgeResolution;
use App\Services\Incidents\DefectiveResolution;
use App\Services\Incidents\MissingResolution;
use App\Services\Incidents\RelocateResolution;
use App\Services\Incidents\ResolvesIncident;
use App\Services\Incidents\RetypeResolution;
use App\Services\Incidents\ReturnResolution;
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
        $reason = $data['reason'] ?? null;

        if (! in_array($reason, IncidentReport::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown incident reason: ' . ($reason ?? 'null'));
        }

        // Everything except `other` resolves into a stock movement, so it cannot
        // be filed without knowing which product and how many. An `other`
        // incident ("the door lock is broken") has neither.
        if (in_array($reason, IncidentReport::STOCK_AFFECTING_REASONS, true)) {
            if (empty($data['product_id'])) {
                throw new \InvalidArgumentException("A product is required for a '{$reason}' incident.");
            }
            if ((int) ($data['quantity'] ?? 0) < 1) {
                throw new \InvalidArgumentException("A quantity of at least 1 is required for a '{$reason}' incident.");
            }
        }

        return DB::transaction(fn () => IncidentReport::create([
            'incident_number'     => $this->nextNumber(),
            'source_type'         => $data['source_type'],
            // A standalone report has no source transaction to point at.
            'source_id'           => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'related_issuance_id' => isset($data['related_issuance_id']) ? (int) $data['related_issuance_id'] : null,
            'location_id'         => (int) $data['location_id'],
            'product_id'          => isset($data['product_id']) ? (int) $data['product_id'] : null,
            'batch_id'            => $data['batch_id'] ?? null,
            'serial_id'           => $data['serial_id'] ?? null,
            'expected_zone'       => $data['expected_zone'] ?? null,
            'actual_zone'         => $data['actual_zone'] ?? null,
            'reason'              => $reason,
            'quantity'            => (int) ($data['quantity'] ?? 0),
            'narrative'           => $data['narrative'] ?? null,
            'occurred_at'         => $data['occurred_at'] ?? null,
            'photo_path'          => $data['photo_path'] ?? null,
            // Client-asserted: both a camera capture and a gallery pick arrive
            // as identical uploads, so this is recorded, never trusted.
            'photo_source'        => $data['photo_source'] ?? null,
            // The cause fields: the nuance that used to be encoded in the reason
            // itself back when there were eight of them. Listed explicitly
            // because this array — not $fillable — is what decides which columns
            // a filing writes; adding them to $fillable alone drops them.
            'last_known_location' => $data['last_known_location'] ?? null,
            'suspected_cause'     => $data['suspected_cause'] ?? null,
            'root_cause'          => $data['root_cause'] ?? null,
            'return_direction'    => $data['return_direction'] ?? null,
            'return_reason'       => $data['return_reason'] ?? null,
            'related_transfer_id' => isset($data['related_transfer_id']) ? (int) $data['related_transfer_id'] : null,
            'reported_by'         => $reportedBy,
            'status_id'           => IncidentReport::statusId('Open'),
        ]));
    }

    /**
     * Batch ids at a location currently held out of FIFO by an unfinished
     * incident — Open, or approved and still awaiting its inspection.
     *
     * Deliberately `unsettled()` rather than `open()`: stock under
     * investigation must stay unpickable until somebody has actually decided
     * what happened to it.
     */
    public function excludedBatchIds(int $locationId): array
    {
        return IncidentReport::unsettled()
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
        // Cheap pre-flight, outside the transaction. Only the confirm path needs
        // a strategy: dismissing produces no artifact, so a reason whose strategy
        // has not shipped yet must still be dismissable. Re-checked under the
        // lock below where it actually matters.
        if ($action === 'confirm') {
            $this->strategyFor($incident->reason)->validate($incident, $options);
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

            // Authoritative dispatch off the freshly-read, locked row rather than
            // the caller's in-memory model.
            $strategy = $this->strategyFor($locked->reason);

            $strategy->validate($locked, $options);
            $posted = $strategy->apply($locked, $options, $reviewedBy);

            $locked->update([
                'status_id'       => $this->statusAfterApply($locked),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $posted->adjustmentId,
                'correction_id'   => $posted->correctionId,
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Approving is not always the end of the story.
     *
     * A defective or returned quantity lands in a hold that still needs an
     * inspection before anyone knows where it goes — so the incident is
     * "Awaiting Inspection", not "Resolved". Reading Resolved while the units
     * sit un-inspected in a corner claims a decision nobody has made.
     *
     * Derived from the holds themselves rather than a second reason map: if the
     * approval left behind a hold that CAN be disposed, an inspection is owed.
     * A missing hold is terminal, so a loss still resolves immediately, and so
     * does anything that created no hold at all (re-type, zone mismatch, other).
     */
    private function statusAfterApply(IncidentReport $incident): int
    {
        $awaiting = StockHold::where('incident_id', $incident->incident_id)
            ->held()
            ->get()
            ->contains(fn (StockHold $h) => ! empty(StockHold::ALLOWED_DISPOSITIONS[$h->condition] ?? []));

        return IncidentReport::statusId($awaiting ? 'Awaiting Inspection' : 'Resolved');
    }

    /**
     * Map a reason to the strategy that posts its artifact.
     *
     * No default arm: `match` throws UnhandledMatchError if a reason is added
     * without a strategy, which is exactly the failure we want — loud, at the
     * first resolve, rather than an incident silently closing with no artifact.
     */
    private function strategyFor(string $reason): ResolvesIncident
    {
        return match ($reason) {
            IncidentReport::REASON_MISSING       => new MissingResolution(),
            IncidentReport::REASON_DEFECTIVE     => new DefectiveResolution(),
            IncidentReport::REASON_RETURN        => new ReturnResolution(),
            IncidentReport::REASON_WRONG_TYPE    => new RetypeResolution(),
            IncidentReport::REASON_ZONE_MISMATCH => new RelocateResolution(),
            IncidentReport::REASON_OTHER         => new AcknowledgeResolution(),
        };
    }
}
