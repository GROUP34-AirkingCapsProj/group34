<?php

namespace App\Support;

/**
 * The supplier's cover on one unit: how long they stand behind it, counted from
 * the day it arrived rather than the day a customer bought it.
 *
 * The dates and the supplier travel WITH the state because an inspector told
 * "expired" immediately needs to know when it expired and who the supplier was —
 * a bare state word sends them to another screen to find out whether it is worth
 * arguing about.
 */
class SupplierWarranty
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
        public readonly int $months = 0,
        public readonly ?int $supplierId = null,
        public readonly ?string $supplierName = null,
        public readonly ?string $receivingNumber = null,
        public readonly ?string $poNumber = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state'            => $this->state,
            'start_date'       => $this->startDate,
            'end_date'         => $this->endDate,
            'months'           => $this->months,
            'supplier_id'      => $this->supplierId,
            'supplier_name'    => $this->supplierName,
            'receiving_number' => $this->receivingNumber,
            'po_number'        => $this->poNumber,
        ];
    }
}
