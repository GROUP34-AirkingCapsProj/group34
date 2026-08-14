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

        if ($awaitingInspection === null || $awaitingApproval === null) {
            return;
        }

        // Already inspected and NOT sent back: the admin owes a decision.
        //
        // `rejection_note IS NULL` is an exact marker rather than a guess,
        // because submitInspection() nulls the note (IncidentService.php:255) —
        // so a note can only survive on a report the admin sent back that the
        // inspector has not yet resubmitted.
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
