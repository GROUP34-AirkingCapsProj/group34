<?php

namespace App\Services;

use App\Exceptions\WarrantyClaimException;
use App\Models\AuditTrail;
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
            return $this->addReplacementUnit($claim, $locationId, $candidates);
        }
        return $this->addRepairPart($claim, $locationId, $candidates, (float) ($data['quantity'] ?? 1));
    }

    private function addRepairPart(WarrantyClaim $claim, int $locationId, array $candidates, float $qty): WarrantyClaimDetail
    {
        $product = $this->resolveRepairPart($candidates);
        if (! $product) {
            throw new WarrantyClaimException('Part not found. Scan a stocked consumable spare part.');
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

    /**
     * Resolve a repair part from a scanned code, mirroring the issuance flow:
     * product barcode/code -> item_serial.serial_number -> receiving_details.scanned_barcode.
     * Consumables often have a NULL products.barcode; their scannable code lives in
     * item_serial / receiving_details (from the PO receiving scan), so all three are tried.
     */
    private function resolveRepairPart(array $candidates): ?Product
    {
        // 1) products.barcode / products.product_code
        $product = $this->findProductByBarcodeCandidates($candidates);
        if ($product) {
            return $product;
        }

        // 2) item_serial.serial_number -> product
        foreach ($candidates as $candidate) {
            $norm = strtolower(trim($candidate));
            if ($norm === '') continue;
            $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [$norm])->first();
            if ($serial) {
                return Product::find($serial->product_id);
            }
        }

        // 3) receiving_details.scanned_barcode -> product
        foreach ($candidates as $candidate) {
            $norm = strtolower(trim($candidate));
            if ($norm === '') continue;
            $row = DB::table('receiving_details')
                ->whereRaw("LOWER(TRIM(COALESCE(scanned_barcode, ''))) = ?", [$norm])
                ->whereNotNull('product_id')
                ->select('product_id')
                ->first();
            if ($row) {
                return Product::find($row->product_id);
            }
        }

        return null;
    }

    private function addReplacementUnit(WarrantyClaim $claim, int $locationId, array $candidates): WarrantyClaimDetail
    {
        if ($claim->details()->exists()) {
            throw new WarrantyClaimException('A replacement claim can only include one unit.');
        }

        // Look up + lock the in-stock serial INSIDE the transaction so two
        // concurrent replacement claims can't both consume the same unit.
        return DB::transaction(function () use ($claim, $locationId, $candidates) {
            $serial = null;
            foreach ($candidates as $candidate) {
                $serial = ItemSerial::where('location_id', $locationId)
                    ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($candidate)])
                    ->where('status_id', self::STATUS_SERIAL_IN_STOCK)
                    ->lockForUpdate()
                    ->first();
                if ($serial) break;
            }
            if (! $serial) {
                throw new WarrantyClaimException('Replacement unit not found in stock at this location. Scan a unit serial.');
            }

            $product = Product::find($serial->product_id);

            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)->lockForUpdate()->first();
            if (! $inv || $inv->quantity_on_hand < 1) {
                throw new WarrantyClaimException('Insufficient stock for the replacement unit.');
            }
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
            $inv->available_quantity = max(0, $inv->available_quantity - 1);
            $inv->save();

            $canonicalSerial = $serial->serial_number;
            $serial->delete();

            $unitCost = (float) ($product->cost_price ?? 0);
            $detail = $claim->details()->create([
                'product_id'         => $product->product_id,
                'serial_number'      => $canonicalSerial,
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

    /** @throws WarrantyClaimException */
    public function removeItem(WarrantyClaim $claim, int $detailId): void
    {
        $this->assertPending($claim);

        $detail = $claim->details()->where('claim_detail_id', $detailId)->first();
        if (! $detail) {
            throw new WarrantyClaimException('Item not found on this claim.');
        }
        $product = Product::with(['applianceDetail', 'consumableDetail'])->find($detail->product_id);

        DB::transaction(function () use ($claim, $detail, $product) {
            $inv = Inventory::where('product_id', $detail->product_id)
                ->where('location_id', $claim->location_id)->lockForUpdate()->first();

            if ($inv) {
                $inv->quantity_on_hand   += $detail->quantity_in_pieces;
                $inv->available_quantity += $detail->quantity_in_pieces;
                if ($product && ! $product->isSerialized()) {
                    $ppp = $product->getPiecesPerPackage();
                    if ($ppp > 0) {
                        $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);
                    }
                }
                $inv->save();
            }

            if ($product && $product->isSerialized() && $detail->serial_number) {
                ItemSerial::create([
                    'product_id'    => $detail->product_id,
                    'location_id'   => $claim->location_id,
                    'serial_number' => $detail->serial_number,
                    'serial_type'   => 'received',
                    'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                    'scanned_by'    => Auth::id(),
                ]);
            }

            $detail->delete();
            $this->refreshPartsCost($claim);
        });
    }

    /** @throws WarrantyClaimException */
    public function completeClaim(WarrantyClaim $claim): WarrantyClaim
    {
        $this->assertPending($claim);

        if (! $claim->details()->exists()) {
            throw new WarrantyClaimException('Add at least one item before completing the claim.');
        }
        if ($claim->claim_type === 'replacement' && $claim->details()->count() !== 1) {
            throw new WarrantyClaimException('A replacement claim must contain exactly one unit.');
        }

        return DB::transaction(function () use ($claim) {
            $warranty = WarrantyRecord::lockForUpdate()->find($claim->warranty_id);

            if (! $warranty || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE || $warranty->is_expired) {
                throw new WarrantyClaimException('Warranty is no longer claimable.');
            }

            // 1. Mark original warranty Claimed.
            $warranty->update([
                'status_id'   => self::STATUS_WARRANTY_CLAIMED,
                'claim_date'  => now()->toDateString(),
                'claimed_by'  => Auth::id(),
                'claim_notes' => $claim->notes,
            ]);

            // 2. Replacement -> new warranty inheriting the original end date.
            if ($claim->claim_type === 'replacement') {
                $unit = $claim->details()->first();
                WarrantyRecord::create([
                    'product_id'              => $unit->product_id,
                    'serial_number'           => $unit->serial_number,
                    'customer_id'             => $claim->customer_id,
                    'sale_date'               => now()->toDateString(),
                    'warranty_start_date'     => now()->toDateString(),
                    'warranty_end_date'       => $warranty->warranty_end_date,
                    'warranty_period_months'  => $warranty->warranty_period_months,
                    'warranty_duration_label' => $warranty->warranty_duration_label,
                    'status_id'               => self::STATUS_WARRANTY_ACTIVE,
                    'notes'                   => 'Replacement under claim ' . $claim->claim_number,
                ]);
            }

            // 3. Finalize claim.
            $claim->update([
                'status_id'    => $this->claimStatusId('Completed'),
                'parts_cost'   => (float) $claim->details()->sum('line_cost'),
                'completed_at' => now(),
            ]);

            // 4. Audit trail (best-effort).
            try {
                AuditTrail::create([
                    'user_id'    => Auth::id(),
                    'action'     => 'warranty_claim_completed',
                    'table_name' => 'warranty_claims',
                    'record_id'  => $claim->claim_id,
                    'old_values' => null,
                    'new_values' => [
                        'claim_number' => $claim->claim_number,
                        'claim_type'   => $claim->claim_type,
                        'warranty_id'  => $claim->warranty_id,
                        'serial'       => $claim->serial_number,
                        'items'        => $claim->details()->count(),
                        'parts_cost'   => $claim->parts_cost,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $claim->fresh(['details', 'warranty', 'customer']);
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
