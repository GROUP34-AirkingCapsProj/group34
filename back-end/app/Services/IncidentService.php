<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use Illuminate\Support\Facades\DB;

/**
 * Incident reports are the only legal way to break FIFO order. Filing one
 * quarantines the affected items immediately so picking can continue past them;
 * nothing is deducted until an inspector has examined the goods and an admin has
 * confirmed what they found.
 *
 * Exclusion is derived from the ITEMS' own status rather than a flag column on
 * inventory_batches, so confirming or dismissing a report returns the stock to
 * rotation with no cleanup step.
 *
 * There is no per-reason resolution strategy any more: the inspector records an
 * outcome on each physical item, and IncidentItemService::applyOutcomes applies
 * every type uniformly.
 */
class IncidentService
{
    /**
     * Reasons that move no stock and therefore create no items.
     *
     * Both are confirmable straight from Open. Demanding five photographs and
     * two signatures to record that a box is on the wrong shelf is how a process
     * teaches people not to report things.
     */
    public const INSPECTION_EXEMPT_REASONS = [
        IncidentReport::REASON_ZONE_MISMATCH,
        IncidentReport::REASON_OTHER,
    ];

    public function __construct(private IncidentItemService $items)
    {
    }

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

        return DB::transaction(function () use ($data, $reason, $reportedBy) {
            $incident = IncidentReport::create([
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
                // The client's document block, filled by the reporter — they are
                // the ones who know which dealer and customer the unit went to.
                'dealer_name'         => $data['dealer_name'] ?? null,
                'dealer_phone'        => $data['dealer_phone'] ?? null,
                'dealer_mobile'       => $data['dealer_mobile'] ?? null,
                'dealer_email'        => $data['dealer_email'] ?? null,
                'branch_address'      => $data['branch_address'] ?? null,
                'customer_name'       => $data['customer_name'] ?? null,
                'customer_contact'    => $data['customer_contact'] ?? null,
                'site_address'        => $data['site_address'] ?? null,
                'purchased_at'        => $data['purchased_at'] ?? null,
                'received_at_dor'     => $data['received_at_dor'] ?? null,
                'reported_by'         => $reportedBy,
                'status_id'           => IncidentReport::statusId('Open'),
            ]);

            if (! in_array($reason, self::INSPECTION_EXEMPT_REASONS, true)) {
                $this->items->quarantine($incident, $this->itemRowsFrom($data));
            }

            return $incident;
        });
    }

    /**
     * The physical things the report is about.
     *
     * A guided filing sends `items` — one row per unit, so a split-system's
     * indoor and outdoor halves arrive as two. The picking modal has no such
     * step and sends only the flat product/batch/serial/quantity, so that shape
     * is folded into a single item rather than being rejected; dropping it would
     * silently stop quarantining everything filed from a pick.
     *
     * @return array<int,array<string,mixed>>
     */
    private function itemRowsFrom(array $data): array
    {
        // Derived from the counterparty and STORED, so a later edit to the
        // report cannot retroactively flip arithmetic that has already run.
        $inbound = ($data['reason'] ?? null) === IncidentReport::REASON_RETURN
            && in_array($data['return_counterparty'] ?? null, IncidentReport::INBOUND_COUNTERPARTIES, true);

        $rows = $data['items'] ?? null;

        if (is_array($rows) && $rows !== []) {
            // Stamped onto every row rather than left to the caller: direction
            // is a property of the report's counterparty, not of the individual
            // unit, so a client cannot send half a return inbound.
            // array_merge, not `+`: `+` keeps a key the row already has, which
            // would let a client's own is_inbound override the derived value.
            return array_map(fn (array $row) => array_merge($row, ['is_inbound' => $inbound]), $rows);
        }

        return [[
            'product_id'    => (int) $data['product_id'],
            'batch_id'      => $data['batch_id'] ?? null,
            'serial_id'     => $data['serial_id'] ?? null,
            'quantity'      => (int) ($data['quantity'] ?? 1),
            'unit_role'     => IncidentItem::ROLE_SINGLE,
            'serial_number' => $data['serial_number'] ?? null,
            'model_code'    => $data['model_code'] ?? null,
            'is_inbound'    => $inbound,
        ]];
    }

    /**
     * Batch ids at a location currently unpickable because an item on an
     * unfinished report names them.
     *
     * Read off ITEM status, not the incident's. Deriving it from the incident
     * broke once already: adding "Awaiting Inspection" silently returned stock
     * under investigation to the picking pool, because the query filtered on
     * "Open". An item is quarantined until somebody has actually decided what
     * happened to it, whatever the paperwork says.
     */
    public function excludedBatchIds(int $locationId): array
    {
        return IncidentItem::quarantinedBatchIds($locationId);
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
     * The inspector submits their findings: Open -> Awaiting Inspection.
     *
     * Touches NO stock. Everything recorded here — the findings, the ASC tick,
     * the names on the hardcopy that gets signed by hand — is evidence for the
     * admin's decision, and the per-item outcomes it points at are applied only
     * when that decision is `confirm`.
     */
    public function submitInspection(IncidentReport $incident, array $data, int $inspectorId): IncidentReport
    {
        return DB::transaction(function () use ($incident, $data, $inspectorId) {
            $locked = IncidentReport::whereKey($incident->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new \RuntimeException('This incident no longer exists.');
            }

            // Awaiting Inspection is accepted as well as Open because a rejected
            // report sits at Awaiting Inspection: the admin sends it back to the
            // inspector, who fixes what the note asked for and submits again. A
            // Resolved or Dismissed report is finished and takes no more.
            $allowed = [
                IncidentReport::statusId('Open'),
                IncidentReport::statusId('Awaiting Inspection'),
            ];

            if (! in_array((int) $locked->status_id, $allowed, true)) {
                throw new \RuntimeException('This incident has already been decided.');
            }

            $locked->update([
                'status_id'                => IncidentReport::statusId('Awaiting Inspection'),
                'inspected_by'             => $inspectorId,
                'inspected_at'             => now(),
                'inspection_findings'      => $data['inspection_findings'] ?? null,
                'checked_by_asc'           => (bool) ($data['checked_by_asc'] ?? false),
                'signed_by_representative' => $data['signed_by_representative'] ?? null,
                'signed_by_branch_manager' => $data['signed_by_branch_manager'] ?? null,
                'signed_at'                => $data['signed_at'] ?? null,
                // A resubmission answers the rejection, so the note stops being
                // the current state of the report and would otherwise sit on the
                // admin's screen describing a round that is already over.
                'rejection_note'           => null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * The admin's decision on a submitted inspection.
     *
     * 'confirm' applies every item outcome and closes the report — this is the
     * one and only moment stock moves; 'reject' returns it to the inspector with
     * a note, leaving the evidence and the outcomes intact; 'dismiss' closes it
     * as raised in error, releasing the quarantine without moving anything.
     */
    public function decide(IncidentReport $incident, string $action, array $options, int $adminId): IncidentReport
    {
        if (! in_array($action, ['confirm', 'reject', 'dismiss'], true)) {
            throw new \InvalidArgumentException("Unknown decision: {$action}");
        }

        return DB::transaction(function () use ($incident, $action, $options, $adminId) {
            // Lock the row and re-check the status UNDER the lock, so two
            // reviewers deciding at once cannot both pass the guard and
            // double-book the movement. A plain read outside the transaction
            // would let both through.
            $locked = IncidentReport::whereKey($incident->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new \RuntimeException('This incident no longer exists.');
            }

            $status = (int) $locked->status_id;

            if (in_array($status, [
                IncidentReport::statusId('Resolved'),
                IncidentReport::statusId('Dismissed'),
            ], true)) {
                throw new \RuntimeException('This incident has already been decided.');
            }

            return match ($action) {
                'confirm' => $this->confirm($locked, $options, $adminId),
                'reject'  => $this->reject($locked, $options),
                'dismiss' => $this->dismiss($locked, $options, $adminId),
            };
        });
    }

    private function confirm(IncidentReport $locked, array $options, int $adminId): IncidentReport
    {
        $exempt = in_array($locked->reason, self::INSPECTION_EXEMPT_REASONS, true);

        if (! $exempt && (int) $locked->status_id !== IncidentReport::statusId('Awaiting Inspection')) {
            throw new \RuntimeException(
                'This incident has not been inspected yet. An inspection must be submitted before it can be confirmed.'
            );
        }

        // A zone mismatch or an `other` report has no items, and applyOutcomes
        // rightly refuses an empty settlement — so the call is skipped rather
        // than the guard weakened.
        if (IncidentItem::where('incident_id', $locked->incident_id)->exists()) {
            $this->items->applyOutcomes($locked, $adminId);
        }

        $locked->update([
            'status_id'       => IncidentReport::statusId('Resolved'),
            'reviewed_by'     => $adminId,
            'reviewed_at'     => now(),
            'resolution_note' => $options['resolution_note'] ?? null,
        ]);

        return $locked->fresh();
    }

    /**
     * Send it back to the inspector.
     *
     * The status does not move: the report is already Awaiting Inspection and
     * stays there, because rejection returns it to the inspector's queue, not to
     * filing. Only the note changes, and the evidence and the per-item outcomes
     * are left alone so the inspector edits rather than re-enters them.
     */
    private function reject(IncidentReport $locked, array $options): IncidentReport
    {
        if ((int) $locked->status_id !== IncidentReport::statusId('Awaiting Inspection')) {
            throw new \RuntimeException('There is no submitted inspection to reject.');
        }

        $locked->update([
            'status_id'      => IncidentReport::statusId('Awaiting Inspection'),
            'rejection_note' => $options['rejection_note'] ?? $options['resolution_note'] ?? null,
        ]);

        return $locked->fresh();
    }

    /**
     * Raised in error: release the quarantine and close it.
     *
     * Nothing was ever deducted, so nothing is given back — the items simply
     * become available again and their batches return to FIFO rotation.
     */
    private function dismiss(IncidentReport $locked, array $options, int $adminId): IncidentReport
    {
        $this->items->release($locked);

        $locked->update([
            'status_id'       => IncidentReport::statusId('Dismissed'),
            'reviewed_by'     => $adminId,
            'reviewed_at'     => now(),
            'resolution_note' => $options['resolution_note'] ?? null,
        ]);

        return $locked->fresh();
    }
}
