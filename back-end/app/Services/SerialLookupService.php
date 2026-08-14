<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\ItemSerial;
use App\Models\WarrantyRecord;
use App\Support\SerialLookup;
use App\Traits\NormalizesBarcode;
use Illuminate\Support\Facades\DB;

/**
 * Where is this unit, and what covers it?
 *
 * Selling a unit DELETES its item_serial row (IssuanceScanController deletes it
 * at scan time and re-creates it if the unit is taken back off the issuance), so
 * the presence of that row is itself the answer to "is it still in inventory".
 * Everything here follows from that one fact.
 */
class SerialLookupService
{
    use NormalizesBarcode;

    public function __construct(private WarrantyStateService $warranty)
    {
    }

    public function find(string $serial): SerialLookup
    {
        $candidates = $this->barcodeLookupCandidates($this->canonicalSerial($serial));

        if ($candidates === []) {
            return new SerialLookup(SerialLookup::UNKNOWN, $serial);
        }

        // Order matters. The first match wins, and `returned` deliberately
        // outranks `sold`: a unit brought back by a customer gets no serial row
        // restored today, so checking the sale first would place a unit standing
        // in the warehouse with a customer — a confident wrong answer, which is
        // worse than the "not found" this replaces.
        return $this->inStock($candidates)
            ?? $this->returned($candidates)
            ?? $this->sold($candidates)
            ?? $this->soldWithoutWarranty($candidates)
            ?? new SerialLookup(SerialLookup::UNKNOWN, $serial);
    }

    /** @param array<int,string> $candidates */
    private function inStock(array $candidates): ?SerialLookup
    {
        $row = DB::table('item_serial as s')
            ->leftJoin('locations as l', 'l.location_id', '=', 's.location_id')
            ->leftJoin('products as p', 'p.product_id', '=', 's.product_id')
            ->leftJoin('status_lookup as sl', 'sl.status_id', '=', 's.status_id')
            ->where(fn ($q) => $this->matchSerial($q, 's.serial_number', $candidates))
            ->first([
                's.serial_number', 's.product_id', 's.receiving_id', 's.serial_type',
                'l.location_name', 'p.product_name', 'sl.status_name',
            ]);

        if ($row === null) {
            return null;
        }

        return new SerialLookup(
            state:        SerialLookup::IN_STOCK,
            serialNumber: $row->serial_number,
            unit: [
                'product_id'    => (int) $row->product_id,
                'product_name'  => $row->product_name,
                'location_name' => $row->location_name,
                'status_name'   => $row->status_name,
                'serial_type'   => $row->serial_type,
            ],
            supplierWarranty: $this->warranty->coverFor(
                (int) $row->product_id,
                $row->receiving_id !== null ? (int) $row->receiving_id : null
            ),
            // A unit nobody has bought has no customer warranty and will not have
            // one until it is sold. That is the normal state of stock, not a gap.
            customerWarranty: ['state' => 'none', 'reason' => 'not_sold'],
        );
    }

    /** @param array<int,string> $candidates */
    private function returned(array $candidates): ?SerialLookup
    {
        $item = DB::table('incident_items as ii')
            ->join('incident_reports as ir', 'ir.incident_id', '=', 'ii.incident_id')
            ->leftJoin('status_lookup as sl', 'sl.status_id', '=', 'ir.status_id')
            ->leftJoin('products as p', 'p.product_id', '=', 'ii.product_id')
            ->where('ii.is_inbound', true)
            ->whereNotIn('sl.status_name', ['Dismissed'])
            ->where(fn ($q) => $this->matchSerial($q, 'ii.serial_number', $candidates))
            ->orderByDesc('ii.item_id')
            ->first([
                'ii.serial_number', 'ii.product_id', 'ii.status as item_status',
                'ir.incident_number', 'ir.incident_id', 'sl.status_name as incident_status',
                'p.product_name',
            ]);

        if ($item === null) {
            return null;
        }

        // Who returned it is exactly what the person holding the unit wants to
        // know, so the warranty record answers when no issuance detail does —
        // a unit registered to a customer but issued before serial capture, or
        // through a flow that never wrote the detail, still has a buyer on file.
        $sale = $this->saleFor($candidates) ?? $this->saleFromWarranty($candidates);

        return new SerialLookup(
            state:        SerialLookup::RETURNED,
            serialNumber: $item->serial_number,
            unit: [
                'product_id'   => (int) $item->product_id,
                'product_name' => $item->product_name,
                'status_name'  => $item->item_status,
            ],
            sale: $sale,
            incident: [
                'incident_id'     => (int) $item->incident_id,
                'incident_number' => $item->incident_number,
                'incident_status' => $item->incident_status,
                'item_status'     => $item->item_status,
            ],
            customerWarranty: $this->customerWarrantyFor($candidates),
        );
    }

    /** @param array<int,string> $candidates */
    private function sold(array $candidates): ?SerialLookup
    {
        $warranty = WarrantyRecord::with(['product', 'customer'])
            ->where(fn ($q) => $this->matchSerial($q, 'serial_number', $candidates))
            ->latest('warranty_id')
            ->first();

        if ($warranty === null) {
            return null;
        }

        return new SerialLookup(
            state:            SerialLookup::SOLD,
            serialNumber:     $warranty->serial_number,
            unit: [
                'product_id'   => (int) $warranty->product_id,
                'product_name' => $warranty->product?->product_name,
            ],
            sale:             $this->saleFor($candidates) ?? $this->saleFromWarranty($candidates),
            supplierWarranty: $this->coverForSoldUnit($candidates, (int) $warranty->product_id),
            customerWarranty: $this->customerWarrantyFor($candidates),
        );
    }

    /**
     * The unit left the building but no warranty was written for it.
     *
     * Reported as its own state rather than folded into "unknown", because it is
     * a real defect with a customer on the other end of it: an appliance sold
     * without its serial scanned produces no warranty and says nothing at the
     * time.
     *
     * @param array<int,string> $candidates
     */
    private function soldWithoutWarranty(array $candidates): ?SerialLookup
    {
        $sale = $this->saleFor($candidates);

        if ($sale === null) {
            return null;
        }

        return new SerialLookup(
            state:        SerialLookup::SOLD_NO_WARRANTY,
            serialNumber: $sale['serial_number'],
            unit: [
                'product_id'   => $sale['product_id'],
                'product_name' => $sale['product_name'],
            ],
            sale:             $sale,
            supplierWarranty: $this->coverForSoldUnit($candidates, (int) $sale['product_id']),
            customerWarranty: ['state' => 'none', 'reason' => 'no_record_for_sale'],
        );
    }

    /**
     * The sale this serial went out on, if any.
     *
     * @param array<int,string> $candidates
     * @return array<string,mixed>|null
     */
    private function saleFor(array $candidates): ?array
    {
        $row = DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
            ->leftJoin('products as p', 'p.product_id', '=', 'd.product_id')
            ->where(fn ($q) => $this->matchSerial($q, 'd.serial_number', $candidates))
            ->orderByDesc('d.issuance_detail_id')
            ->first([
                'd.serial_number', 'd.product_id', 'd.receiving_id',
                'p.product_name', 'c.customer_name', 'c.contact_number',
                'i.issuance_date', 'i.dr_number', 'i.issuance_id',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'serial_number'    => $row->serial_number,
            'product_id'       => (int) $row->product_id,
            'product_name'     => $row->product_name,
            'customer_name'    => $row->customer_name,
            'customer_contact' => $row->contact_number,
            'sale_date'        => $row->issuance_date,
            'dr_number'        => $row->dr_number,
            'issuance_id'      => (int) $row->issuance_id,
        ];
    }

    /**
     * The sale as the WARRANTY record knows it.
     *
     * The fallback when no issuance detail carries the serial. It has no DR
     * number to offer — that lives on the issuance — but the buyer and the date
     * are the parts somebody holding the unit actually needs.
     *
     * @param array<int,string> $candidates
     * @return array<string,mixed>|null
     */
    private function saleFromWarranty(array $candidates): ?array
    {
        $warranty = WarrantyRecord::with(['customer', 'product'])
            ->where(fn ($q) => $this->matchSerial($q, 'serial_number', $candidates))
            ->latest('warranty_id')
            ->first();

        if ($warranty === null) {
            return null;
        }

        return [
            'serial_number'    => $warranty->serial_number,
            'product_id'       => (int) $warranty->product_id,
            'product_name'     => $warranty->product?->product_name,
            'customer_name'    => $warranty->customer?->customer_name,
            'customer_contact' => $warranty->customer?->contact_number,
            'sale_date'        => $warranty->sale_date?->toDateString(),
            'dr_number'        => null,
            'issuance_id'      => $warranty->issuance_id !== null ? (int) $warranty->issuance_id : null,
        ];
    }

    /**
     * Supplier cover for a unit that has left: its receiving is on the issuance
     * detail, because the serial row it came from is gone.
     *
     * @param array<int,string> $candidates
     */
    private function coverForSoldUnit(array $candidates, int $productId): \App\Support\SupplierWarranty
    {
        $receivingId = DB::table('issuance_details as d')
            ->where(fn ($q) => $this->matchSerial($q, 'd.serial_number', $candidates))
            ->orderByDesc('d.issuance_detail_id')
            ->value('d.receiving_id');

        return $this->warranty->coverFor(
            $productId,
            $receivingId !== null ? (int) $receivingId : null
        );
    }

    /**
     * @param array<int,string> $candidates
     * @return array<string,mixed>
     */
    private function customerWarrantyFor(array $candidates): array
    {
        $warranty = WarrantyRecord::where(fn ($q) => $this->matchSerial($q, 'serial_number', $candidates))
            ->latest('warranty_id')
            ->first();

        if ($warranty === null) {
            return ['state' => 'none', 'reason' => 'no_record_for_sale'];
        }

        // Derived, never read off status_id: the stored column only changes when
        // `warranties:expire` runs, and nothing here may depend on that.
        $state = match ($warranty->effective_status_id) {
            WarrantyRecord::STATUS_CLAIMED => 'claimed',
            WarrantyRecord::STATUS_EXPIRED => 'expired',
            default                        => 'in_warranty',
        };

        return [
            'state'         => $state,
            'start_date'    => $warranty->warranty_start_date?->toDateString(),
            'end_date'      => $warranty->warranty_end_date?->toDateString(),
            'months'        => (int) $warranty->warranty_period_months,
            'label'         => $warranty->warranty_duration_label,
            'warranty_id'   => (int) $warranty->warranty_id,
            'is_claimable'  => $state === 'in_warranty',
        ];
    }

    /**
     * Match a serial the way the rest of the system does — tolerant of the case,
     * dashes and spaces a person retypes differently from the sticker.
     *
     * @param array<int,string> $candidates
     */
    private function matchSerial($query, string $column, array $candidates)
    {
        foreach ($candidates as $i => $candidate) {
            $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("LOWER(TRIM({$column})) = ?", [strtolower(trim($candidate))]);

            $normalized = strtolower((string) preg_replace('/[^0-9A-Za-z]/', '', $candidate));
            if ($normalized !== '') {
                $query->orWhereRaw(
                    "LOWER(REPLACE(REPLACE(REPLACE(TRIM({$column}), '-', ''), ' ', ''), ':', '')) = ?",
                    [$normalized]
                );
            }
        }

        return $query;
    }
}
