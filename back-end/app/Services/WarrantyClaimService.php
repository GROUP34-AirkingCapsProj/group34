<?php

namespace App\Services;

use App\Exceptions\WarrantyClaimException;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\StatusLookup;
use App\Models\WarrantyClaim;
use App\Models\WarrantyClaimDetail;
use App\Models\WarrantyRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WarrantyClaimService
{
    use \App\Traits\NormalizesBarcode;

    private const STATUS_WARRANTY_ACTIVE  = 33;
    private const STATUS_WARRANTY_CLAIMED = 35;
    private const STATUS_SERIAL_IN_STOCK  = 26;

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

    /**
     * Add one item to a pending claim and deduct it from inventory.
     * Repair -> a consumable (barcode + quantity). Replacement -> one serialized unit.
     *
     * @throws WarrantyClaimException
     */
    public function addItem(WarrantyClaim $claim, array $data): WarrantyClaimDetail
    {
        $this->assertPending($claim);

        $locationId = (int) $claim->location_id;
        $raw        = trim((string) ($data['barcode'] ?? ''));
        if ($raw === '') {
            throw new WarrantyClaimException('Barcode is required.');
        }
        $candidates = $this->barcodeLookupCandidates($raw);

        if ($claim->claim_type === 'replacement') {
            return $this->addReplacementUnit($claim, $locationId, $raw, $candidates);
        }
        return $this->addRepairPart($claim, $locationId, $candidates, (float) ($data['quantity'] ?? 1));
    }

    private function addRepairPart(WarrantyClaim $claim, int $locationId, array $candidates, float $qty): WarrantyClaimDetail
    {
        $product = $this->findProductByBarcodeCandidates($candidates);
        if (! $product) {
            throw new WarrantyClaimException('Part not found. Scan a stocked consumable barcode.');
        }
        $product->load('consumableDetail.unit');
        if ($product->isSerialized()) {
            throw new WarrantyClaimException('Repairs use consumable parts only. This is a serialized appliance.');
        }

        $pieces = (int) max(1, ceil($qty));
        $unitName = $product->consumableDetail?->unit?->unit_name ?? 'piece';

        return DB::transaction(function () use ($claim, $product, $locationId, $pieces, $unitName) {
            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)->lockForUpdate()->first();

            if (! $inv || $inv->quantity_on_hand < $pieces) {
                throw new WarrantyClaimException('Insufficient stock for this part.');
            }

            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $pieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $pieces);
            $ppp = $product->getPiecesPerPackage();
            if ($ppp > 0) {
                $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);
            }
            $inv->save();

            $unitCost = (float) ($product->cost_price ?? 0);
            $detail = $claim->details()->create([
                'product_id'         => $product->product_id,
                'serial_number'      => null,
                'quantity_issued'    => $pieces,
                'quantity_in_pieces' => $pieces,
                'quantity_unit'      => $unitName,
                'unit_cost'          => $unitCost,
                'line_cost'          => round($unitCost * $pieces, 2),
            ]);

            $this->refreshPartsCost($claim);
            return $detail;
        });
    }

    private function addReplacementUnit(WarrantyClaim $claim, int $locationId, string $raw, array $candidates): WarrantyClaimDetail
    {
        if ($claim->details()->exists()) {
            throw new WarrantyClaimException('A replacement claim can only include one unit.');
        }

        $serial = null;
        foreach ($candidates as $candidate) {
            $serial = ItemSerial::where('location_id', $locationId)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($candidate)])
                ->where('status_id', self::STATUS_SERIAL_IN_STOCK)
                ->first();
            if ($serial) break;
        }
        if (! $serial) {
            throw new WarrantyClaimException('Replacement unit not found in stock at this location. Scan a unit serial.');
        }

        $product = Product::find($serial->product_id);

        return DB::transaction(function () use ($claim, $product, $serial, $locationId, $raw) {
            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)->lockForUpdate()->first();
            if (! $inv || $inv->quantity_on_hand < 1) {
                throw new WarrantyClaimException('Insufficient stock for the replacement unit.');
            }
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
            $inv->available_quantity = max(0, $inv->available_quantity - 1);
            $inv->save();

            $serial->delete();

            $unitCost = (float) ($product->cost_price ?? 0);
            $detail = $claim->details()->create([
                'product_id'         => $product->product_id,
                'serial_number'      => $raw,
                'quantity_issued'    => 1,
                'quantity_in_pieces' => 1,
                'quantity_unit'      => 'unit',
                'unit_cost'          => $unitCost,
                'line_cost'          => $unitCost,
            ]);

            $this->refreshPartsCost($claim);
            return $detail;
        });
    }

    private function assertPending(WarrantyClaim $claim): void
    {
        if ((int) $claim->status_id !== $this->claimStatusId('Pending')) {
            throw new WarrantyClaimException('This claim is no longer editable.');
        }
    }

    private function refreshPartsCost(WarrantyClaim $claim): void
    {
        $claim->update([
            'parts_cost' => (float) $claim->details()->sum('line_cost'),
        ]);
    }
}
