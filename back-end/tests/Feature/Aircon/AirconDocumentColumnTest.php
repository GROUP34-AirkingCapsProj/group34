<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\Customer;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Supplier;
use App\Models\User;
use App\Support\IssuanceStatus;

/**
 * The storage guarantee behind aircon grouping on documents: a purchase order,
 * issuance or receiving line can carry the aircon model it belongs to, an
 * untagged line stays perfectly valid, and archiving a model never destroys
 * document history.
 *
 * Grouping logic — deriving the header count from the tagged lines — is not
 * tested here; it does not exist yet (Task 13). This file covers the column.
 */
class AirconDocumentColumnTest extends AirconTestCase
{
    private function airconModel(): AirconModel
    {
        return AirconModel::create([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ]);
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Doc Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function purchaseOrder(User $user, Location $location): PurchaseOrder
    {
        $supplier = Supplier::create(['supplier_name' => 'Doc Supplier ' . uniqid()]);

        return PurchaseOrder::create([
            'supplier_id'         => $supplier->supplier_id,
            'location_id'         => $location->location_id,
            'po_number'           => 'PO-AC-' . uniqid(),
            'order_date'          => today()->toDateString(),
            'verification_status' => 'verified',
            'total_amount'        => 0,
            'grand_total'         => 0,
            'created_by'          => $user->user_id,
        ]);
    }

    private function poDetail(?int $airconModelId): PurchaseOrderDetail
    {
        $user     = $this->actingAsAdmin();
        $location = $this->location();

        return PurchaseOrderDetail::create([
            'po_id'            => $this->purchaseOrder($user, $location)->po_id,
            'location_id'      => $location->location_id,
            'product_id'       => $this->unitProduct('indoor')->product_id,
            'aircon_model_id'  => $airconModelId,
            'quantity_ordered' => 3,
            'unit_price'       => 10000,
            'subtotal'         => 30000,
        ]);
    }

    private function issuanceDetail(?int $airconModelId): IssuanceDetail
    {
        $user     = $this->actingAsAdmin();
        $location = $this->location();
        $customer = Customer::create([
            'customer_name' => 'Doc Customer ' . uniqid(),
            'customer_type' => 'individual',
        ]);

        $issuance = Issuance::create([
            'location_id'      => $location->location_id,
            'customer_id'      => $customer->customer_id,
            'issuance_date'    => now()->toDateString(),
            'issuance_type'    => 'individual',
            'dr_number'        => 'DR-AC-' . uniqid(),
            'discount_type'    => 'none',
            'discount_percent' => 0,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'issued_by'        => $user->user_id,
            'status_id'        => IssuanceStatus::draft(),
        ]);

        return IssuanceDetail::create([
            'issuance_id'          => $issuance->issuance_id,
            'product_id'           => $this->unitProduct('outdoor')->product_id,
            'aircon_model_id'      => $airconModelId,
            'unit_price'           => 10000,
            'quantity_issued'      => 3,
            'quantity_unit'        => 'unit',
            'quantity_in_pieces'   => 3,
            'unit_price_per_piece' => 10000,
            'subtotal'             => 30000,
            'condition_issued'     => 'Good',
        ]);
    }

    private function receivingDetail(?int $airconModelId): ReceivingDetail
    {
        $user     = $this->actingAsAdmin();
        $location = $this->location();
        $po       = $this->purchaseOrder($user, $location);

        $receiving = Receiving::create([
            'po_id'                   => $po->po_id,
            'supplier_id'             => $po->supplier_id,
            'location_id'             => $location->location_id,
            'receiving_number'        => 'GRN-AC-' . uniqid(),
            'receiving_date'          => today()->toDateString(),
            'received_by'             => $user->user_id,
            'total_quantity_received' => 3,
        ]);

        // po_detail_id deliberately left null: this mirrors the scan-driven
        // receipt that has no purchase-order line behind it, which is exactly
        // why receiving_details carries its own aircon_model_id.
        return ReceivingDetail::create([
            'receiving_id'     => $receiving->receiving_id,
            'product_id'       => $this->unitProduct('indoor')->product_id,
            'aircon_model_id'  => $airconModelId,
            'quantity_amount'  => 3,
            'prod_price'       => 10000,
            'condition'        => 'Good',
        ]);
    }

    public function test_a_purchase_order_line_stores_and_reads_back_its_aircon_model(): void
    {
        $model = $this->airconModel();
        $line  = $this->poDetail($model->aircon_model_id);

        $this->assertSame(
            (int) $model->aircon_model_id,
            (int) $line->fresh()->aircon_model_id
        );
    }

    public function test_an_issuance_line_stores_and_reads_back_its_aircon_model(): void
    {
        $model = $this->airconModel();
        $line  = $this->issuanceDetail($model->aircon_model_id);

        $this->assertSame(
            (int) $model->aircon_model_id,
            (int) $line->fresh()->aircon_model_id
        );
    }

    public function test_a_receiving_line_stores_and_reads_back_its_aircon_model(): void
    {
        $model = $this->airconModel();
        $line  = $this->receivingDetail($model->aircon_model_id);

        $this->assertSame(
            (int) $model->aircon_model_id,
            (int) $line->fresh()->aircon_model_id
        );
    }

    public function test_an_untagged_line_is_still_valid_on_every_document_table(): void
    {
        // Every row that existed before this column is one of these.
        $this->assertNull($this->poDetail(null)->fresh()->aircon_model_id);
        $this->assertNull($this->issuanceDetail(null)->fresh()->aircon_model_id);
        $this->assertNull($this->receivingDetail(null)->fresh()->aircon_model_id);
    }

    /**
     * The guarantee that matters most. A cascade here would silently destroy
     * purchase-order, issuance and receiving history the first time somebody
     * cleaned up an obsolete model.
     */
    public function test_hard_deleting_a_model_nulls_the_tag_but_keeps_the_document_lines(): void
    {
        $model = $this->airconModel();

        $poLine  = $this->poDetail($model->aircon_model_id);
        $issLine = $this->issuanceDetail($model->aircon_model_id);
        $rcvLine = $this->receivingDetail($model->aircon_model_id);

        // forceDelete, not delete: AirconModel soft-deletes, and a soft delete
        // never reaches the foreign key. This is the real row disappearing.
        $model->forceDelete();

        foreach ([$poLine, $issLine, $rcvLine] as $line) {
            $survivor = $line->fresh();
            $this->assertNotNull(
                $survivor,
                'deleting an aircon model must never delete a document line: ' . $line::class
            );
            $this->assertNull(
                $survivor->aircon_model_id,
                'the tag must be nulled, not cascaded: ' . $line::class
            );
        }
    }

    public function test_the_aircon_model_relation_resolves_on_every_document_line(): void
    {
        $model = $this->airconModel();

        $lines = [
            $this->poDetail($model->aircon_model_id),
            $this->issuanceDetail($model->aircon_model_id),
            $this->receivingDetail($model->aircon_model_id),
        ];

        foreach ($lines as $line) {
            $related = $line->airconModel;

            $this->assertInstanceOf(AirconModel::class, $related, $line::class);
            $this->assertSame($model->model_name, $related->model_name, $line::class);
        }

        // And an untagged line resolves to null rather than blowing up.
        $this->assertNull($this->poDetail(null)->airconModel);
    }

    public function test_a_product_can_appear_on_two_lines_of_the_same_document_group(): void
    {
        // Grouping is per model, not per product: the indoor and outdoor lines
        // of one aircon carry the same aircon_model_id with no unique key
        // standing in the way.
        $model = $this->airconModel();

        $first  = $this->poDetail($model->aircon_model_id);
        $second = PurchaseOrderDetail::create([
            'po_id'            => $first->po_id,
            'location_id'      => $first->location_id,
            'product_id'       => $this->unitProduct('outdoor')->product_id,
            'aircon_model_id'  => $model->aircon_model_id,
            'quantity_ordered' => 3,
            'unit_price'       => 12000,
            'subtotal'         => 36000,
        ]);

        $this->assertSame(
            2,
            PurchaseOrderDetail::where('po_id', $first->po_id)
                ->where('aircon_model_id', $model->aircon_model_id)
                ->count()
        );
        $this->assertNotSame($first->product_id, $second->product_id);
    }
}
