<?php

namespace App\Support;

/**
 * Everything known about one physical unit, keyed on the number printed on it.
 *
 * The blocks are nullable because they genuinely do not all apply: a unit on
 * the shelf has no sale, a sold unit has no location, and only a unit that came
 * back has an incident. A caller reads `state` first and the rest follows from
 * it.
 */
class SerialLookup
{
    public const IN_STOCK         = 'in_stock';
    public const RETURNED         = 'returned';
    public const SOLD             = 'sold';
    public const SOLD_NO_WARRANTY = 'sold_no_warranty';
    public const UNKNOWN          = 'unknown';

    /**
     * @param array<string,mixed>|null $unit     where it is, when it is here
     * @param array<string,mixed>|null $sale     who bought it, and when
     * @param array<string,mixed>|null $incident the report holding it, if any
     */
    public function __construct(
        public readonly string $state,
        public readonly string $serialNumber,
        public readonly ?array $unit = null,
        public readonly ?array $sale = null,
        public readonly ?array $incident = null,
        public readonly ?SupplierWarranty $supplierWarranty = null,
        public readonly ?array $customerWarranty = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state'             => $this->state,
            'serial_number'     => $this->serialNumber,
            'unit'              => $this->unit,
            'sale'              => $this->sale,
            'incident'          => $this->incident,
            'supplier_warranty' => $this->supplierWarranty?->toArray()
                ?? ['state' => 'none'],
            'customer_warranty' => $this->customerWarranty
                ?? ['state' => 'none'],
        ];
    }
}
