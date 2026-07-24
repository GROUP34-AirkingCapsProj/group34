<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Product;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Builds the line list of a Draft issuance. Every mutation here is illegal once
 * the order leaves Draft — the confirmed quote is what the customer agreed to,
 * so it must not move underneath the picker.
 */
class IssuanceOrderService
{
    /** Expand a set into lines, multiplied by how many sets the customer wants. */
    public function addSet(Issuance $issuance, int $setId, int $setQuantity): void
    {
        $this->assertDraft($issuance);

        if ($setQuantity < 1) {
            throw new \InvalidArgumentException('Set quantity must be at least 1.');
        }

        $set = ProductSet::with(['lines.product', 'lines.issuableUnit'])->findOrFail($setId);

        DB::transaction(function () use ($issuance, $set, $setQuantity) {
            $group = $issuance->sets()->create([
                'set_id'            => $set->set_id,
                'set_quantity'      => $setQuantity,
                'set_name_snapshot' => $set->set_name,
                'was_modified'      => false,
            ]);

            foreach ($set->lines as $line) {
                $pieces = $line->quantityInPieces() * $setQuantity;
                $price  = (float) ($line->product?->unit_price ?? 0);

                $issuance->details()->create([
                    'issuance_set_id'     => $group->issuance_set_id,
                    'product_id'          => $line->product_id,
                    'quantity_requested'  => $pieces,
                    'quantity_issued'     => 0,
                    'quantity_short'      => 0,
                    'unit_price'          => $price,
                    'cost_price_at_issue' => null, // snapshotted at confirm-quote
                    'subtotal'            => round($pieces * $price, 2),
                ]);
            }
        });
    }

    /** Add a loose product that belongs to no set. */
    public function addCustomLine(Issuance $issuance, int $productId, int $quantity): IssuanceDetail
    {
        $this->assertDraft($issuance);

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $product = Product::findOrFail($productId);

        return $issuance->details()->create([
            'issuance_set_id'     => null,
            'product_id'          => $product->product_id,
            'quantity_requested'  => $quantity,
            'quantity_issued'     => 0,
            'quantity_short'      => 0,
            'unit_price'          => (float) $product->unit_price,
            'cost_price_at_issue' => null,
            'subtotal'            => round($quantity * (float) $product->unit_price, 2),
        ]);
    }

    public function updateLine(Issuance $issuance, int $detailId, int $quantity): IssuanceDetail
    {
        $this->assertDraft($issuance);

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $detail = $issuance->details()->where('issuance_detail_id', $detailId)->firstOrFail();

        $detail->update([
            'quantity_requested' => $quantity,
            'subtotal'           => round($quantity * (float) $detail->unit_price, 2),
        ]);

        $this->flagModified($detail);

        return $detail->fresh();
    }

    public function removeLine(Issuance $issuance, int $detailId): void
    {
        $this->assertDraft($issuance);

        $detail = $issuance->details()->where('issuance_detail_id', $detailId)->firstOrFail();
        $this->flagModified($detail);
        $detail->delete();
    }

    /** A set group whose lines were touched no longer represents the standard BOQ. */
    private function flagModified(IssuanceDetail $detail): void
    {
        if ($detail->issuance_set_id === null) {
            return;
        }

        $detail->loadMissing('issuanceSet');
        $detail->issuanceSet?->update(['was_modified' => true]);
    }

    private function assertDraft(Issuance $issuance): void
    {
        if ((int) $issuance->status_id !== IssuanceStatus::draft()) {
            throw new \RuntimeException('Only a Draft issuance can have its lines changed.');
        }
    }
}
