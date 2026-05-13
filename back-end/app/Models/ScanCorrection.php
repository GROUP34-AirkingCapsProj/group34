<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanCorrection extends Model
{
    protected $table      = 'scan_corrections';
    protected $primaryKey = 'correction_id';

    protected $fillable = [
        'adjustment_id',
        'po_id',
        'receiving_id',
        'original_barcode',
        'original_product_id',
        'corrected_barcode',
        'corrected_product_id',
        'correction_type',
        'quantity_before',
        'quantity_after',
        'serial_number_before',
        'serial_number_after',
        'receiving_detail_id',
        'serial_id',
        'reason',
        'corrected_by',
        'approved_by',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_after'  => 'integer',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'adjustment_id');
    }

    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id', 'product_id');
    }

    public function correctedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'corrected_product_id', 'product_id');
    }

    public function receivingDetail(): BelongsTo
    {
        return $this->belongsTo(ReceivingDetail::class, 'receiving_detail_id', 'receiving_detail_id');
    }

    public function serialRecord(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'serial_id', 'serial_id');
    }

    public function correctedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by', 'user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }
}
