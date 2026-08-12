<?php

namespace App\Services;

use App\Models\IncidentItem;
use Illuminate\Support\Facades\DB;

/**
 * Whether a unit is still under warranty, and therefore which endings an
 * inspector may choose for it.
 *
 * Offering a warranty claim on an out-of-warranty unit wastes a warranty
 * officer's time, and it is invisible until they open the claim and find
 * nothing to claim against.
 */
class WarrantyStateService
{
    public const IN_WARRANTY = 'in_warranty';
    public const EXPIRED     = 'expired';
    public const NONE        = 'none';

    /**
     * Keyed on serial_number, because that is what warranty_records keys on. A
     * record exists only for a unit that was SOLD, via an issuance — so a
     * non-serialized material, or an appliance still in stock, has none.
     *
     * EXPIRED and NONE offer the same choices but are not the same fact: one
     * unit was sold and its cover ran out, the other was never registered at
     * all. Collapsing them hides the second, which is the one worth asking
     * about.
     */
    public function stateFor(IncidentItem $item): string
    {
        $serial = $item->serial_number ?: $item->serial?->serial_number;

        if ($serial === null || $serial === '') {
            return self::NONE;
        }

        $record = DB::table('warranty_records')
            ->where('serial_number', $serial)
            ->orderByDesc('warranty_end_date')
            ->first(['warranty_end_date']);

        if ($record === null) {
            return self::NONE;
        }

        return $record->warranty_end_date !== null
            && $record->warranty_end_date >= now()->toDateString()
                ? self::IN_WARRANTY
                : self::EXPIRED;
    }

    /**
     * Only claim_warranty is gated. return_to_supplier stays available
     * throughout — sending goods back commercially has nothing to do with
     * whether they are under warranty.
     *
     * write_off is in the base list for the same reason: it is how a `missing`
     * incident is settled, and warranty has no bearing on a unit nobody can
     * find. Leaving it out would make theft and loss reports unclosable, which
     * is the opposite of gating one outcome that does not apply.
     *
     * @return array<int,string>
     */
    public function allowedOutcomes(IncidentItem $item): array
    {
        return $this->outcomesForState($this->stateFor($item));
    }

    /**
     * The same rule keyed on an already-resolved state, so a caller that needs
     * both the state and the list — every payload does — pays for one lookup
     * rather than two. At 500 rows that is the difference between one query per
     * serialized item and two.
     *
     * @return array<int,string>
     */
    public function outcomesForState(string $state): array
    {
        $base = [
            IncidentItem::OUTCOME_RESTOCK,
            IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            IncidentItem::OUTCOME_REPAIR,
            IncidentItem::OUTCOME_SCRAP,
            IncidentItem::OUTCOME_WRITE_OFF,
        ];

        if ($state === self::IN_WARRANTY) {
            array_splice($base, 2, 0, [IncidentItem::OUTCOME_CLAIM_WARRANTY]);
        }

        return $base;
    }
}
