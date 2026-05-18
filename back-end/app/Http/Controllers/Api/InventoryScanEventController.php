<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryScanEvent;
use App\Models\TransactionTypeLookup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class InventoryScanEventController extends Controller
{
    use ApiResponse;

    // Map inventory_scan_events.transaction_type varchar → transaction_type_lookup.transaction_type_id
    private const TYPE_ID_MAP = [
        'receiving' => 1,
        'issuance'  => 2,
        'transfer'  => 3,
        'audit'     => 4,
    ];

    public function index(Request $request)
    {
        try {
            // Load lookup names once
            $lookupNames = TransactionTypeLookup::pluck('transaction_name', 'transaction_type_id');

            $query = InventoryScanEvent::with([
                'product',
                'user',
                'location',
                'toLocation',
            ])->where('action', 'applied');

            if ($request->filled('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }
            if ($request->filled('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->filled('search')) {
                $term = $request->search;
                $query->where(function ($q) use ($term) {
                    $q->where('barcode', 'like', "%{$term}%")
                      ->orWhereHas('product', fn($p) => $p->where('product_name', 'like', "%{$term}%")
                                                           ->orWhere('product_code', 'like', "%{$term}%"))
                      ->orWhereJsonContains('meta->receiving_number', $term)
                      ->orWhereJsonContains('meta->transfer_number', $term);
                });
            }

            $perPage = min((int) $request->get('per_page', 50), 200);
            $events  = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Append resolved type name to each record
            $events->getCollection()->transform(function ($event) use ($lookupNames) {
                $typeId = self::TYPE_ID_MAP[$event->transaction_type] ?? null;
                $event->transaction_type_name = $typeId ? ($lookupNames[$typeId] ?? $event->transaction_type) : $event->transaction_type;
                return $event;
            });

            return $this->paginated($events, 'Scan events retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve scan events: ' . $e->getMessage(), 500);
        }
    }
}
