<?php

namespace App\Services;

use App\Exceptions\WarrantyClaimException;
use App\Models\StatusLookup;
use App\Models\WarrantyClaim;
use App\Models\WarrantyRecord;
use Illuminate\Support\Facades\Auth;

class WarrantyClaimService
{
    private const STATUS_WARRANTY_ACTIVE  = 33;
    private const STATUS_WARRANTY_CLAIMED = 35;

    public function claimStatusId(string $name): int
    {
        $id = StatusLookup::where('status_category', 'warranty_claim')
            ->where('status_name', $name)
            ->value('status_id');

        if (! $id) {
            throw new WarrantyClaimException("Missing warranty_claim status '{$name}'. Run migrations.");
        }
        return (int) $id;
    }

    /**
     * Validate the warranty for the given serial and create a pending claim.
     *
     * @throws WarrantyClaimException on any validation failure.
     */
    public function initClaim(array $data): WarrantyClaim
    {
        $serial = trim((string) ($data['serial_number'] ?? ''));

        $warranty = WarrantyRecord::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($serial)])
            ->latest('warranty_id')
            ->first();

        if (! $warranty) {
            throw new WarrantyClaimException('No warranty record found for this serial number.');
        }
        if ($warranty->status_id === self::STATUS_WARRANTY_CLAIMED) {
            throw new WarrantyClaimException('This warranty has already been claimed.');
        }
        if ($warranty->is_expired || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE) {
            throw new WarrantyClaimException('This warranty is expired and cannot be claimed.');
        }
        if ((int) $warranty->customer_id !== (int) $data['customer_id']) {
            throw new WarrantyClaimException('This warranty belongs to a different customer.');
        }

        return WarrantyClaim::create([
            'claim_number'  => $this->generateClaimNumber(),
            'warranty_id'   => $warranty->warranty_id,
            'customer_id'   => $warranty->customer_id,
            'product_id'    => $warranty->product_id,
            'serial_number' => $warranty->serial_number,
            'claim_type'    => $data['claim_type'],
            'notes'         => $data['notes'] ?? null,
            'location_id'   => $data['location_id'] ?? null,
            'status_id'     => $this->claimStatusId('Pending'),
            'parts_cost'    => 0,
            'created_by'    => Auth::id(),
        ]);
    }

    private function generateClaimNumber(): string
    {
        return 'WC-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    }
}
