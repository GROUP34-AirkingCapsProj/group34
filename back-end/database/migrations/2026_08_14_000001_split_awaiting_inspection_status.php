<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split the two meanings that shared `Awaiting Inspection`.
 *
 * It was written both by submitInspection() — the inspector is done, the ADMIN
 * must decide — and by reject() — the admin sent it back, the INSPECTOR must
 * act. The inspector's queue and the admin's list both read that one status and
 * reached opposite conclusions, so every submitted inspection reappeared in the
 * inspector's queue wearing a "Sent back" badge.
 *
 * `Awaiting Approval` now carries the first meaning. `Awaiting Inspection`
 * keeps its name and finally means what it says.
 *
 * The status row is inserted HERE rather than left to IncidentStatusSeeder: a
 * live deploy is not guaranteed to re-seed, and IncidentReport::statusId()
 * throws on a missing name — so the first submitted inspection after deploy
 * would 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('status_lookup')->updateOrInsert(
            ['status_category' => 'incident', 'status_name' => 'Awaiting Approval'],
            ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );

        $awaitingInspection = $this->statusId('Awaiting Inspection');
        $awaitingApproval   = $this->statusId('Awaiting Approval');

        // Expected on a fresh install: migrations run before seeders, so there
        // is no Awaiting Inspection row yet and nothing to split.
        if ($awaitingInspection === null) {
            return;
        }

        // Impossible unless the updateOrInsert above silently did nothing —
        // a broken deploy. Aborting here names the cause while it is still
        // legible; returning would print a reassuring "moved 0" and leave
        // statusId() to throw on the first submitted inspection instead.
        if ($awaitingApproval === null) {
            throw new \RuntimeException(
                'No incident status named Awaiting Approval immediately after inserting one: '
                . 'the updateOrInsert above did nothing. Refusing to report a no-op split.'
            );
        }

        // Already inspected and NOT sent back: the admin owes a decision.
        //
        // `rejection_note IS NULL` is exact for the rows this scans. When they
        // were written the invariant was the CONTROLLER's alone — its
        // `required_if:action,reject` was the only thing standing between a
        // rejection and a NULL note, because reject() persisted whatever it was
        // handed. reject() now requires a non-empty note itself and throws
        // without one, so the guarantee is the service's too; the historical
        // rows below simply predate that. The other half has always held
        // unconditionally: submitInspection() nulls the note, so a surviving
        // note can only mean "sent back and not yet resubmitted".
        //
        // Rows rejected before 2026_08_12_000003 added the column would also
        // carry NULL and be read as submitted; closed in practice, as the live
        // counts confirm no such row exists.
        $moved = DB::table('incident_reports')
            ->where('status_id', $awaitingInspection)
            ->whereNull('rejection_note')
            ->whereNotNull('inspected_at')
            ->update(['status_id' => $awaitingApproval]);

        // Everything else stays put, which leaves it with the INSPECTOR. That
        // is the recoverable direction: a report wrongly given to the inspector
        // costs one resubmit, while one wrongly given to the admin can be
        // confirmed — and confirming is the single moment stock moves.
        $left = DB::table('incident_reports')
            ->where('status_id', $awaitingInspection)
            ->count();

        echo "  split_awaiting_inspection_status: moved {$moved} to Awaiting Approval, "
            . "left {$left} with the inspector" . PHP_EOL;
    }

    /**
     * Lossy, deliberately and unavoidably.
     *
     * Moving every Awaiting Approval row back re-merges the two populations
     * that up() separated. Rolling forward again re-splits them by the same
     * rule, so a report rejected during the rollback window would be misrouted.
     * The status row itself is left in place — dropping it would break any row
     * still pointing at it.
     */
    public function down(): void
    {
        $awaitingInspection = $this->statusId('Awaiting Inspection');
        $awaitingApproval   = $this->statusId('Awaiting Approval');

        if ($awaitingInspection === null || $awaitingApproval === null) {
            return;
        }

        DB::table('incident_reports')
            ->where('status_id', $awaitingApproval)
            ->update(['status_id' => $awaitingInspection]);
    }

    private function statusId(string $name): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');

        return $id === null ? null : (int) $id;
    }
};
