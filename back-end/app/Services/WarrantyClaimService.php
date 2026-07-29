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
use App\Models\WarrantyClaimReturn;
use App\Models\WarrantyInventoryMovement;
use App\Models\ProductSet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WarrantyClaimService
{
    use \App\Traits\NormalizesBarcode;

    private const STATUS_WARRANTY_ACTIVE  = 33;
    private const STATUS_WARRANTY_CLAIMED = 35;
    private const STATUS_SERIAL_IN_STOCK  = 26;

    public function addCustomOrderLine(WarrantyClaim $claim, int $productId, int $pieces): void
    {
        $this->assertPending($claim);
        $product = Product::with(['applianceDetail', 'consumableDetail.unit'])->findOrFail($productId);
        $this->assertProductAllowedForClaim($claim, $product);
        $pieces = max(1, $pieces);
        if ($claim->claim_type === 'replacement'
            && ((int) $claim->details()->sum('quantity_requested') + $pieces) > 1) {
            throw new WarrantyClaimException('A replacement order may contain exactly one unit.');
        }

        $existing = $claim->details()->where('product_id', $productId)
            ->whereNull('serial_number')->where('quantity_issued', 0)->first();
        if ($existing) {
            $existing->increment('quantity_requested', $pieces);
            return;
        }
        $claim->details()->create([
            'product_id' => $productId, 'line_source' => 'custom',
            'quantity_requested' => $pieces, 'quantity_issued' => 0,
            'quantity_in_pieces' => 0,
            'quantity_unit' => $product->isConsumable()
                ? ($product->consumableDetail?->unit?->unit_name ?? 'piece') : 'unit',
            'unit_cost' => $product->isConsumable()
                ? $product->getPricePerPiece((float) $product->cost_price)
                : (float) $product->cost_price,
            'line_cost' => 0,
        ]);
    }

    public function addSetOrder(WarrantyClaim $claim, int $setId, int $setQuantity): void
    {
        $this->assertPending($claim);
        $set = ProductSet::with('lines.product')->findOrFail($setId);
        DB::transaction(function () use ($claim, $set, $setQuantity) {
            foreach ($set->lines as $line) {
                $product = $line->product;
                if (! $product) continue;
                $this->assertProductAllowedForClaim($claim, $product);
                $pieces = $line->quantityInPieces() * max(1, $setQuantity);
                $this->addCustomOrderLine($claim, (int) $product->product_id, $pieces);
                $claim->details()->where('product_id', $product->product_id)
                    ->where('quantity_issued', 0)->latest('claim_detail_id')->limit(1)
                    ->update(['product_set_id' => $set->set_id, 'line_source' => 'set']);
            }
        });
    }

    public function updateOrderLine(WarrantyClaim $claim, int $detailId, int $pieces): void
    {
        $this->assertPending($claim);
        $detail = $claim->details()->findOrFail($detailId);
        if ((int) $detail->quantity_issued > max(1, $pieces)) {
            throw new WarrantyClaimException('Requested quantity cannot be below the quantity already fulfilled.');
        }
        $detail->update(['quantity_requested' => max(1, $pieces)]);
    }

    public function removeOrderLine(WarrantyClaim $claim, int $detailId): void
    {
        $this->assertPending($claim);
        $detail = $claim->details()->findOrFail($detailId);
        if ((int) $detail->quantity_issued > 0) {
            throw new WarrantyClaimException('A fulfilled line cannot be removed. Reverse its scans first.');
        }
        $detail->delete();
    }

    private function assertProductAllowedForClaim(WarrantyClaim $claim, Product $product): void
    {
        if ($claim->claim_type === 'repair' && $product->isSerialized()) {
            throw new WarrantyClaimException('Repair orders may contain consumable repair parts only.');
        }
        if ($claim->claim_type === 'replacement' && ! $product->isSerialized()) {
            throw new WarrantyClaimException('Replacement orders may contain serialized replacement units only.');
        }
    }

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
        $serial = $this->canonicalSerial((string) ($data['serial_number'] ?? ''));
        $candidates = $this->barcodeLookupCandidates($serial);

        $warranty = null;
        foreach ($candidates as $candidate) {
            $normalizedCandidate = strtolower((string) preg_replace('/[^0-9A-Za-z]/', '', $candidate));
            $warranty = WarrantyRecord::whereRaw(
                'LOWER(TRIM(serial_number)) = ?',
                [strtolower(trim($candidate))]
            )
                ->when($normalizedCandidate !== '', fn ($query) => $query->orWhereRaw(
                    "LOWER(REPLACE(REPLACE(REPLACE(TRIM(serial_number), '-', ''), ' ', ''), ':', '')) = ?",
                    [$normalizedCandidate]
                ))
                ->latest('warranty_id')
                ->first();
            if ($warranty) {
                break;
            }
        }

        if (! $warranty) {
            throw new WarrantyClaimException('No warranty record found for this serial number.');
        }
        if ($warranty->status_id === self::STATUS_WARRANTY_CLAIMED) {
            throw new WarrantyClaimException('This warranty has already been claimed.');
        }
        if ($warranty->is_expired || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE) {
            throw new WarrantyClaimException('This warranty is expired and cannot be claimed.');
        }
        if (! empty($data['customer_id'])
            && (int) $warranty->customer_id !== (int) $data['customer_id']) {
            throw new WarrantyClaimException('This warranty belongs to a different customer.');
        }

        return DB::transaction(function () use ($warranty, $data) {
        $claim = WarrantyClaim::create([
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
        WarrantyClaimReturn::create([
            'claim_id'       => $claim->claim_id,
            'product_id'     => $warranty->product_id,
            'serial_number'  => $warranty->serial_number,
            'location_id'    => $data['location_id'],
            'holding_status' => 'warranty_holding',
            'received_at'    => now(),
        ]);
        return $claim;
        });
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
        $raw        = $this->canonicalSerial((string) ($data['barcode'] ?? ''));
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
            $detail = $claim->details()->where('product_id', $product->product_id)
                ->whereColumn('quantity_issued', '<', 'quantity_requested')
                ->lockForUpdate()->first();
            if (! $detail) {
                throw new WarrantyClaimException('This part is not on the warranty order, or its ordered quantity is already fulfilled.');
            }
            $remaining = (int) $detail->quantity_requested - (int) $detail->quantity_issued;
            if ($pieces > $remaining) {
                throw new WarrantyClaimException("Only {$remaining} piece(s) remain to be scanned for this order line.");
            }
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

            // Consumable catalog cost is stored per package; claim quantities
            // are deducted in base pieces, matching the issuance flow.
            $unitCost = $product->getPricePerPiece((float) ($product->cost_price ?? 0));
            $newIssued = (int) $detail->quantity_issued + $pieces;
            $detail->update([
                'quantity_issued'    => $newIssued,
                'quantity_in_pieces' => $newIssued,
                'quantity_unit'      => $unitName,
                'unit_cost'          => $unitCost,
                'line_cost'          => round($unitCost * $newIssued, 2),
            ]);
            WarrantyInventoryMovement::create([
                'claim_id' => $claim->claim_id, 'claim_detail_id' => $detail->claim_detail_id,
                'product_id' => $product->product_id, 'location_id' => $locationId,
                'movement_type' => 'warranty_repair_stock_out', 'quantity' => $pieces,
                'performed_by' => Auth::id(), 'moved_at' => now(),
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
            $detail = $claim->details()->where('product_id', $product->product_id)
                ->whereColumn('quantity_issued', '<', 'quantity_requested')
                ->lockForUpdate()->first();
            if (! $detail) {
                throw new WarrantyClaimException('This replacement product is not on the warranty order, or its ordered quantity is already fulfilled.');
            }

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
            $detail->update([
                'serial_number'      => $canonicalSerial,
                'quantity_issued'    => 1,
                'quantity_in_pieces' => 1,
                'quantity_unit'      => 'unit',
                'unit_cost'          => $unitCost,
                'line_cost'          => $unitCost,
            ]);
            WarrantyInventoryMovement::create([
                'claim_id' => $claim->claim_id, 'claim_detail_id' => $detail->claim_detail_id,
                'product_id' => $product->product_id, 'location_id' => $locationId,
                'movement_type' => 'warranty_replacement_stock_out', 'quantity' => 1,
                'serial_number' => $canonicalSerial, 'performed_by' => Auth::id(), 'moved_at' => now(),
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
            $issuedPieces = (int) $detail->quantity_in_pieces;
            $issuedSerial = $detail->serial_number;
            $inv = Inventory::where('product_id', $detail->product_id)
                ->where('location_id', $claim->location_id)->lockForUpdate()->first();

            if ($inv) {
                $inv->quantity_on_hand   += $issuedPieces;
                $inv->available_quantity += $issuedPieces;
                if ($product && ! $product->isSerialized()) {
                    $ppp = $product->getPiecesPerPackage();
                    if ($ppp > 0) {
                        $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);
                    }
                }
                $inv->save();
            }

            if ($product && $product->isSerialized() && $issuedSerial) {
                ItemSerial::create([
                    'product_id'    => $detail->product_id,
                    'location_id'   => $claim->location_id,
                    'serial_number' => $issuedSerial,
                    'serial_type'   => 'received',
                    'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                    'scanned_by'    => Auth::id(),
                ]);
            }

            $detail->update([
                'serial_number' => null,
                'quantity_issued' => 0,
                'quantity_in_pieces' => 0,
                'line_cost' => 0,
            ]);
            WarrantyInventoryMovement::create([
                'claim_id' => $claim->claim_id, 'product_id' => $detail->product_id,
                'location_id' => $claim->location_id, 'movement_type' => 'warranty_stock_out_reversed',
                'quantity' => -1 * $issuedPieces,
                'serial_number' => $issuedSerial, 'performed_by' => Auth::id(), 'moved_at' => now(),
            ]);
            $this->refreshPartsCost($claim);
        });
    }

    /** @throws WarrantyClaimException */
    public function completeClaim(WarrantyClaim $claim): WarrantyClaim
    {
        $this->assertPending($claim);

        if (! $claim->details()->exists()) {
            throw new WarrantyClaimException('Add at least one order line before completing the claim.');
        }
        if ($claim->details()->whereColumn('quantity_issued', '<', 'quantity_requested')->exists()) {
            throw new WarrantyClaimException('Complete every ordered line before completing the claim.');
        }
        if ($claim->claim_type === 'replacement' && $claim->details()->count() !== 1) {
            throw new WarrantyClaimException('A replacement claim must contain exactly one unit.');
        }

        return DB::transaction(function () use ($claim) {
            $warranty = WarrantyRecord::lockForUpdate()->find($claim->warranty_id);

            if (! $warranty || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE || $warranty->is_expired) {
                throw new WarrantyClaimException('Warranty is no longer claimable.');
            }

            // Repairs preserve the original warranty and remaining coverage.
            // Replacements retire it and create a linked successor warranty.
            if ($claim->claim_type === 'replacement') {
                $warranty->update([
                    'status_id' => self::STATUS_WARRANTY_CLAIMED, 'claim_date' => now()->toDateString(),
                    'claimed_by' => Auth::id(), 'claim_notes' => $claim->notes,
                ]);
                $unit = $claim->details()->first();
                $replacementWarranty = WarrantyRecord::create([
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
                $claim->returnedUnit()->update([
                    'holding_status' => 'replacement_claim_holding',
                    'replacement_warranty_id' => $replacementWarranty->warranty_id,
                ]);
            } else {
                $claim->returnedUnit()->update([
                    'holding_status' => 'returned_to_customer',
                    'released_at' => now(),
                    'release_disposition' => 'repaired_return',
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
