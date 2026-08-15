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
 *
 * The lifecycle. Every status write in this class is one of these five, and
 * each status has exactly one writer and one owner — a status that means two
 * things is the bug this table exists to prevent:
 *
 *   Status               Written by           Whose turn
 *   -------------------- -------------------- --------------------------------
 *   Open                 file()               inspector — or admin directly,
 *                                             for the exempt reasons
 *   Awaiting Inspection  reject()             inspector
 *   Awaiting Approval    submitInspection()   admin
 *   Resolved             confirm()            nobody
 *   Dismissed            dismiss()            nobody
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
                // Superseding return_direction: who the counterparty is, and
                // what went wrong. Direction is derived from the first of these
                // and stored per item as is_inbound. Listed here for the same
                // reason as everything above — this array, not $fillable, is
                // what decides which columns a filing writes.
                'return_counterparty' => $data['return_counterparty'] ?? null,
                'return_reason_code'  => $data['return_reason_code'] ?? null,
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
            // The wrong/correct pair on the flat shape. The `items` branch above
            // carries these through untouched because array_merge preserves
            // every key the validated row already has.
            'expected_product_id' => $data['expected_product_id'] ?? null,
            'expected_quantity'   => isset($data['expected_quantity']) ? (int) $data['expected_quantity'] : null,
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
     * "Open". "Awaiting Approval" is the same trap. An item is quarantined
     * until somebody has actually decided what happened to it, whatever the
     * paperwork says.
     */
    public function excludedBatchIds(int $locationId): array
    {
        return IncidentItem::quarantinedBatchIds($locationId);
    }

    /**
     * Quarantined pieces per product at a location, for availability quotes.
     *
     * The companion to excludedBatchIds(): that one stops a quarantined BATCH
     * being picked, this one stops the quantity being PROMISED. Both are needed,
     * because an item filed without a batch excludes no batch at all, and a
     * quote reads inventory rather than batches — so without this a picker gets
     * handed an order the FIFO engine will then refuse as under review.
     *
     * @return array<int,int> product_id => pieces
     */
    public function quarantinedQuantities(int $locationId): array
    {
        return IncidentItem::quarantinedQuantities($locationId);
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
     * The inspector submits their findings: the report becomes the ADMIN's.
     *
     * Open or Awaiting Inspection -> Awaiting Approval. The two accepted
     * starting points are "never inspected" and "the admin sent it back"; both
     * are the inspector's turn, and submitting ends it.
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

            // The two states that are the inspector's turn. Open is a report
            // nobody has examined; Awaiting Inspection is one the admin sent
            // back, which the inspector fixes and submits again. Awaiting
            // Approval is already with the admin, and a Resolved or Dismissed
            // report is finished — neither takes a submission.
            $allowed = [
                IncidentReport::statusId('Open'),
                IncidentReport::statusId('Awaiting Inspection'),
            ];

            if (! in_array((int) $locked->status_id, $allowed, true)) {
                throw new \RuntimeException('This incident has already been decided.');
            }

            $locked->update([
                'status_id'                => IncidentReport::statusId('Awaiting Approval'),
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

    /**
     * Awaiting Approval -> Resolved, and the one moment stock moves.
     *
     * The exempt reasons are the single place the "a sent-back report cannot be
     * confirmed" guarantee below does not hold: they skip the status check
     * entirely, so an exempt report that was submitted, rejected and then
     * confirmed ends at Resolved still carrying its rejection_note, which
     * nothing here clears. Harmless — those reasons move no stock, and the
     * split migration only scans rows sitting at Awaiting Inspection — but
     * written down rather than left to be discovered.
     */
    private function confirm(IncidentReport $locked, array $options, int $adminId): IncidentReport
    {
        $exempt = in_array($locked->reason, self::INSPECTION_EXEMPT_REASONS, true);

        if (! $exempt && (int) $locked->status_id !== IncidentReport::statusId('Awaiting Approval')) {
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

        // Re-read: applyOutcomes may have just written a shortfall onto the note,
        // and $locked's copy predates it. Assigning the admin's note straight
        // over the top would erase the one record that the stock went missing.
        $shortfall = IncidentReport::whereKey($locked->getKey())->value('resolution_note');
        $adminNote = $options['resolution_note'] ?? null;

        $locked->update([
            'status_id'       => IncidentReport::statusId('Resolved'),
            'reviewed_by'     => $adminId,
            'reviewed_at'     => now(),
            'resolution_note' => $this->mergeNotes($shortfall, $adminNote),
        ]);

        return $locked->fresh();
    }

    /** Keep both halves of the resolution note, and stay null when there is nothing to say. */
    private function mergeNotes(?string $existing, ?string $added): ?string
    {
        $parts = array_values(array_filter([
            trim((string) $existing),
            trim((string) $added),
        ], fn (string $p) => $p !== ''));

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Send it back to the inspector.
     *
     * Awaiting Approval -> Awaiting Inspection. The status MOVES, where it used
     * to stay put: both states shared one status, so a report the admin had
     * just sent back was indistinguishable from one waiting on them, and it sat
     * in the inspector's queue alongside every inspection they had already
     * submitted.
     *
     * The note is REQUIRED here and not only in the controller. An inspector
     * cannot act on "no", and the split above reads a non-null note as "sent
     * back" — so a rejection path that skipped the controller's
     * `required_if:action,reject` would produce a report nothing could tell
     * apart from a submitted one.
     *
     * Only the status and the note change. The evidence and the per-item
     * outcomes are left alone so the inspector edits rather than re-enters
     * them, and submitInspection() clears the note when they resubmit.
     */
    private function reject(IncidentReport $locked, array $options): IncidentReport
    {
        if ((int) $locked->status_id !== IncidentReport::statusId('Awaiting Approval')) {
            throw new \RuntimeException('There is no submitted inspection to reject.');
        }

        // rejection_note only. `resolution_note` is what confirm() and dismiss()
        // write when they CLOSE a report, so accepting it here would let a
        // non-controller caller satisfy "say what to fix" with closing text —
        // landing the report at Awaiting Inspection badged "Sent back: recount
        // came out fine", and satisfying the guard three lines below while
        // doing it. A silent wrong value in place of the loud refusal.
        $note = trim((string) ($options['rejection_note'] ?? ''));

        if ($note === '') {
            throw new \InvalidArgumentException('Sending a report back needs a note saying what to fix.');
        }

        $locked->update([
            'status_id'      => IncidentReport::statusId('Awaiting Inspection'),
            'rejection_note' => $note,
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
        // Required, like a rejection's note and for a stronger reason.
        //
        // Dismissing is the second most consequential thing an admin can do:
        // release() puts every quarantined unit back in the picking pool, and
        // it deliberately discards whatever outcome an inspector recorded. Yet
        // it used to accept silence, while REJECTING — which moves nothing
        // physical — demanded an explanation. So the system asked why for the
        // harmless action and shrugged at the one that changes what is
        // sellable. reviewed_by/reviewed_at name who and when; only this says
        // why, and an inspector who finds a unit they scrapped back on the
        // shelf has nothing else to read.
        //
        // Deliberately NOT status-guarded: "raised in error" is a judgement
        // about whether the report should exist at all, which does not depend
        // on anyone having inspected it. Forcing an inspection before you can
        // throw out a mistake would be the wrong shape.
        $note = trim((string) ($options['resolution_note'] ?? ''));

        if ($note === '') {
            throw new \InvalidArgumentException(
                'Dismissing a report needs a note saying why it was raised in error.'
            );
        }

        $this->items->release($locked);

        $locked->update([
            'status_id'       => IncidentReport::statusId('Dismissed'),
            'reviewed_by'     => $adminId,
            'reviewed_at'     => now(),
            'resolution_note' => $note,
        ]);

        return $locked->fresh();
    }
}
