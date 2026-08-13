<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Support\SupplierWarranty;
use App\Support\WarrantyDecision;
use Illuminate\Support\Carbon;
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
     * The SUPPLIER's cover on this unit — the second clock, and the one that
     * decides whether a defective unit can go back.
     *
     * Counted from the day the goods ARRIVED, not the day they were sold: a unit
     * still in the warehouse has never been sold and never will have a
     * warranty_records row, yet the supplier stands behind it all the same. That
     * asymmetry is the whole reason this exists — stateFor() answering "none"
     * for stock is correct and useless.
     */
    public function supplierStateFor(IncidentItem $item): SupplierWarranty
    {
        $receiving = $this->receivingFor($item);

        if ($receiving === null || $receiving->receiving_date === null) {
            return new SupplierWarranty(state: self::NONE);
        }

        $months = $this->termMonthsFor((int) $item->product_id);

        // No term configured is NOT expired cover. One says the supplier stands
        // behind it for nothing, the other says nobody ever recorded how long —
        // and only the second is a data problem worth chasing.
        if ($months <= 0) {
            return new SupplierWarranty(
                state:           self::NONE,
                startDate:       (string) $receiving->receiving_date,
                supplierId:      $receiving->supplier_id !== null ? (int) $receiving->supplier_id : null,
                supplierName:    $receiving->supplier_name,
                receivingNumber: $receiving->receiving_number,
                poNumber:        $receiving->po_number,
            );
        }

        $start = Carbon::parse($receiving->receiving_date)->toDateString();
        $end   = Carbon::parse($start)->addMonths($months)->toDateString();

        return new SupplierWarranty(
            state:           $end >= now()->toDateString() ? self::IN_WARRANTY : self::EXPIRED,
            startDate:       $start,
            endDate:         $end,
            months:          $months,
            supplierId:      $receiving->supplier_id !== null ? (int) $receiving->supplier_id : null,
            supplierName:    $receiving->supplier_name,
            receivingNumber: $receiving->receiving_number,
            poNumber:        $receiving->po_number,
        );
    }

    /**
     * The receiving this unit arrived on, with its supplier resolved.
     *
     * Serialized units carry receiving_id themselves; everything else reaches it
     * through the batch. COALESCE on the supplier is not defensive padding:
     * every receiving in the live database has a NULL supplier_id and a real PO,
     * so reading the column alone would leave the supplier blank on every unit
     * in the system.
     */
    private function receivingFor(IncidentItem $item): ?object
    {
        $receivingId = $item->serial?->receiving_id;

        if ($receivingId === null && $item->batch_id !== null) {
            $receivingId = DB::table('inventory_batches')
                ->where('batch_id', $item->batch_id)
                ->value('receiving_id');
        }

        if ($receivingId === null) {
            return null;
        }

        return DB::table('receivings as r')
            ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->leftJoin('suppliers as s', 's.supplier_id', '=', DB::raw('COALESCE(r.supplier_id, po.supplier_id)'))
            ->where('r.receiving_id', $receivingId)
            ->first([
                'r.receiving_date',
                'r.receiving_number',
                'po.po_number',
                DB::raw('COALESCE(r.supplier_id, po.supplier_id) AS supplier_id'),
                's.supplier_name',
            ]);
    }

    /**
     * The supplier term, read off the SAME columns the customer clock uses.
     *
     * Deliberately not a separate field: the manufacturer's term is the
     * manufacturer's term whoever is holding the unit, and a second column would
     * start at zero on every appliance already in the catalogue. The
     * computeWarrantyMonths() fallback mirrors IssuanceScanController exactly so
     * the two clocks cannot drift apart.
     */
    private function termMonthsFor(int $productId): int
    {
        $appliance = \App\Models\ApplianceDetail::where('product_id', $productId)->first();

        if ($appliance === null) {
            return 0;
        }

        $months = (int) ($appliance->warranty_period_months ?? 0);

        return $months > 0 ? $months : $appliance->computeWarrantyMonths();
    }

    /**
     * Both clocks and the resulting outcome list, resolved in one pass.
     *
     * Callers take the whole decision rather than asking twice, so the endpoint
     * that ENFORCES the gate and the payload that RENDERS it cannot disagree.
     */
    public function evaluate(IncidentItem $item): WarrantyDecision
    {
        $customerState = $this->stateFor($item);
        $supplier      = $this->supplierStateFor($item);
        $outcomes      = $this->outcomesForState($customerState);

        // Gated for `defective` ONLY. A warranty return needs live cover; a
        // commercial one does not, and a supplier who shipped the wrong item is
        // wrong on day 400 as much as on day 4. Blocking that would strand a
        // return the client is owed.
        if ($item->incident?->reason === IncidentReport::REASON_DEFECTIVE
            && $supplier->state !== self::IN_WARRANTY) {
            $outcomes = array_values(array_filter(
                $outcomes,
                fn (string $o) => $o !== IncidentItem::OUTCOME_RETURN_TO_SUPPLIER
            ));
        }

        return new WarrantyDecision($customerState, $supplier, $outcomes);
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
