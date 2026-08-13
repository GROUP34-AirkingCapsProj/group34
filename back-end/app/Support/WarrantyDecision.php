<?php

namespace App\Support;

/**
 * Everything an inspection screen and its endpoint need to know about one item's
 * warranty position, resolved once.
 *
 * The outcome list lives here rather than being re-derived by each caller: the
 * endpoint's gate and the payload's `allowed_outcomes` MUST be the same list. A
 * screen that offers a button the endpoint then refuses is worse than one that
 * never offered it.
 */
class WarrantyDecision
{
    /** @param array<int,string> $outcomes */
    public function __construct(
        public readonly string $customerState,
        public readonly SupplierWarranty $supplier,
        public readonly array $outcomes,
    ) {
    }
}
