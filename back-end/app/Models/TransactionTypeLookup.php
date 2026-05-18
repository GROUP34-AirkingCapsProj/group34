<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionTypeLookup extends Model
{
    protected $table = 'transaction_type_lookup';
    protected $primaryKey = 'transaction_type_id';

    protected $fillable = [
        'transaction_name',
        'description',
    ];
}
