<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InventoryOperationPermission
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Purchasing analysts can receive without gaining transfer/issuance rights.
        $feature = $request->input('transaction_type') === 'receiving'
            ? 'delivery_receiving' : 'barcode_inventory_processing';

        return app(CheckPermission::class)->handle($request, $next, $feature, 'create');
    }
}
