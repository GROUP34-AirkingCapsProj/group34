<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyInventoryMovement extends Model
{
    // The table's key is movement_id, not Eloquent's default "id". Declaring it
    // matters beyond find(): on insert Laravel asks PostgreSQL for
    // `returning "<key>"`, so leaving the default made every insert here fail
    // with `column "id" does not exist`. MySQL hid it, because lastInsertId()
    // ignores the column name entirely.
    protected $primaryKey = 'movement_id';

    protected $fillable = [
        'claim_id', 'claim_detail_id', 'product_id', 'location_id',
        'movement_type', 'quantity', 'serial_number', 'performed_by', 'moved_at',
    ];
    protected $casts = ['moved_at' => 'datetime'];
}
