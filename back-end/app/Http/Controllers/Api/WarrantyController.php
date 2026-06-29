<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarrantyRecord;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WarrantyController extends Controller
{
    use ApiResponse;

    // Status ID constants — verified against status_lookup
    private const STATUS_ACTIVE  = 33;
    private const STATUS_EXPIRED = 34;
    private const STATUS_CLAIMED = 35;

    // GET /api/warranties
    public function index(Request $request)
    {
        // Batch-expire any warranties past their end date before listing
        WarrantyRecord::where('status_id', self::STATUS_ACTIVE)
            ->whereDate('warranty_end_date', '<', now()->toDateString())
            ->update(['status_id' => self::STATUS_EXPIRED]);

        $query = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
        ])->orderBy('created_at', 'desc');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('status_id')) {
            $query->where('status_id', $request->integer('status_id'));
        }
        if ($request->filled('serial_number')) {
            $query->whereRaw(
                'LOWER(TRIM(serial_number)) = ?',
                [strtolower(trim($request->string('serial_number')))]
            );
        }
        if ($request->filled('date_from')) {
            $query->whereDate('warranty_end_date', '>=', $request->string('date_from'));
        }
        if ($request->boolean('expiring_soon')) {
            $query->where('status_id', self::STATUS_ACTIVE)
                  ->whereDate('warranty_end_date', '>=', now())
                  ->whereDate('warranty_end_date', '<=', now()->addDays(30));
        }

        return $this->paginated(
            $query->paginate($request->integer('per_page', 15)),
            'Warranty records retrieved'
        );
    }

    // GET /api/warranties/{id}
    public function show($id)
    {
        $warranty = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
            'issuance.issuedByUser',
            'claimedByUser',
        ])->find($id);

        if (! $warranty) {
            return $this->notFound('Warranty record not found');
        }

        return $this->success(
            $this->formatWarranty($warranty),
            'Warranty record retrieved'
        );
    }

    // GET /api/warranties/lookup/{serial}
    // Used by the warranty lookup page and warranty-type issuance flow.
    public function lookupBySerial(string $serial)
    {
        // Auto-expire stale active warranties before lookup
        WarrantyRecord::where('status_id', self::STATUS_ACTIVE)
            ->whereDate('warranty_end_date', '<', now()->toDateString())
            ->update(['status_id' => self::STATUS_EXPIRED]);

        $warranty = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
        ])
        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($serial))])
        ->latest()
        ->first();

        if (! $warranty) {
            return response()->json([
                'success' => false,
                'found'   => false,
                'message' => 'No warranty record found for this serial number.',
            ], 404);
        }

        $formatted = $this->formatWarranty($warranty);
        $isExpired = $formatted['is_expired'];
        $isClaimed = $warranty->status_id === self::STATUS_CLAIMED;

        return response()->json([
            'success'      => true,
            'found'        => true,
            'warranty'     => $formatted,
            'is_expired'   => $isExpired,
            'is_claimed'   => $isClaimed,
            'is_claimable' => ! $isExpired && ! $isClaimed,
        ]);
    }

    // PUT /api/warranties/{id}/claim
    // Marks a warranty as claimed. Records who claimed it and when.
    public function markClaimed(Request $request, $id)
    {
        $warranty = WarrantyRecord::find($id);
        if (! $warranty) {
            return $this->notFound('Warranty record not found');
        }

        if ($warranty->status_id === self::STATUS_CLAIMED) {
            return response()->json([
                'success' => false,
                'message' => 'This warranty has already been claimed.',
            ], 422);
        }

        if ($warranty->is_expired) {
            return response()->json([
                'success' => false,
                'message' => 'This warranty has expired and cannot be claimed.',
            ], 422);
        }

        $validated = $request->validate([
            'claim_notes' => 'required|string|max:1000',
        ]);

        $warranty->update([
            'status_id'   => self::STATUS_CLAIMED,
            'claim_date'  => now()->toDateString(),
            'claimed_by'  => Auth::id(),
            'claim_notes' => $validated['claim_notes'],
        ]);

        /** @var WarrantyRecord $fresh */
        $fresh = $warranty->fresh([
            'product.applianceDetail.brand',
            'customer', 'status', 'claimedByUser',
        ]);
        return $this->success(
            $this->formatWarranty($fresh),
            'Warranty marked as claimed'
        );
    }

    // GET /api/warranties/export
    public function export(Request $request)
    {
        $warranties = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
            'claimedByUser',
        ])
        ->when(
            $request->filled('status_id'),
            fn($q) => $q->where('status_id', $request->integer('status_id'))
        )
        ->orderBy('warranty_end_date', 'asc')
        ->get();

        $filename = 'warranties-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($warranties) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Serial Number', 'Product', 'Brand', 'Capacity',
                'Customer', 'Customer Type', 'Company',
                'Sale Date', 'Warranty Start', 'Warranty End',
                'Duration', 'Days Remaining', 'Status',
                'Claim Date', 'Claim Notes', 'Claimed By', 'Location',
            ]);
            foreach ($warranties as $w) {
                $claimedByName = $w->claimedByUser
                    ? trim(($w->claimedByUser->first_name ?? '')
                          . ' ' . ($w->claimedByUser->last_name ?? ''))
                    : '';
                fputcsv($handle, [
                    $w->serial_number,
                    $w->product?->product_name ?? '',
                    $w->product?->applianceDetail?->brand?->brand_name ?? '',
                    $w->product?->applianceDetail?->capacity_rating ?? '',
                    $w->customer?->customer_name ?? '',
                    $w->customer?->customer_type ?? '',
                    $w->customer?->company_name ?? '',
                    $w->sale_date,
                    $w->warranty_start_date,
                    $w->warranty_end_date,
                    $w->warranty_duration_label ?? '',
                    max(0, (int) now()->diffInDays($w->warranty_end_date, false)),
                    $w->status?->status_name ?? '',
                    $w->claim_date ?? '',
                    $w->claim_notes ?? '',
                    $claimedByName,
                    $w->issuance?->location?->location_name ?? '',
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // PRIVATE HELPER
    private function formatWarranty(WarrantyRecord $w): array
    {
        $claimedByName = $w->claimedByUser
            ? trim(($w->claimedByUser->first_name ?? '')
                  . ' ' . ($w->claimedByUser->last_name ?? ''))
            : null;

        return [
            'warranty_id'             => $w->warranty_id,
            'serial_number'           => $w->serial_number,
            'sale_date'               => $w->sale_date?->format('Y-m-d'),
            'warranty_start_date'     => $w->warranty_start_date?->format('Y-m-d'),
            'warranty_end_date'       => $w->warranty_end_date?->format('Y-m-d'),
            'warranty_period_months'  => $w->warranty_period_months,
            'warranty_duration_label' => $w->warranty_duration_label,
            'status_name'             => $w->status?->status_name,
            'status_id'               => $w->status_id,
            'is_expired'              => $w->is_expired,
            'days_remaining'          => $w->days_remaining,
            'claim_date'              => $w->claim_date?->format('Y-m-d'),
            'claim_notes'             => $w->claim_notes,
            'claimed_by_name'         => $claimedByName,
            'notes'                   => $w->notes,
            'product' => [
                'product_id'      => $w->product?->product_id,
                'product_name'    => $w->product?->product_name,
                'product_code'    => $w->product?->product_code,
                'brand_name'      => $w->product?->applianceDetail?->brand?->brand_name,
                'capacity_rating' => $w->product?->applianceDetail?->capacity_rating,
                'variant'         => $w->product?->applianceDetail?->variant,
            ],
            'customer' => $w->customer ? [
                'customer_id'    => $w->customer->customer_id,
                'customer_name'  => $w->customer->customer_name,
                'customer_type'  => $w->customer->customer_type,
                'company_name'   => $w->customer->company_name,
                'contact_number' => $w->customer->contact_number,
            ] : null,
            'location_name' => $w->issuance?->location?->location_name,
        ];
    }
}
