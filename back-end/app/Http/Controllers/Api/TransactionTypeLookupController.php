<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransactionTypeLookup;
use App\Traits\ApiResponse;

class TransactionTypeLookupController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try {
            $types = TransactionTypeLookup::orderBy('transaction_type_id')->get();
            return $this->success($types, 'Transaction types retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve transaction types: ' . $e->getMessage(), 500);
        }
    }
}
