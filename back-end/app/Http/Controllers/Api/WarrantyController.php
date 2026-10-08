<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarrantyRecord;
use App\Services\SerialLookupService;
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
        // No batch UPDATE here any more. A GET that rewrites rows made expiry
        // depend on somebody opening this page, so every other reader saw a
        // stale "Active" until they did. The state is derived per record now
        // (WarrantyRecord::effective_status_id) and `warranties:expire` tidies
        // the stored column on its own schedule.
        $query = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
        ])->forUser($request->user())
          ->orderBy('created_at', 'desc');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('status_id')) {
            // Filtered on the DERIVED state, so the list agrees with the badges
            // it renders. A plain status_id match listed warranties that lapsed
            // last week under "Active" purely because nothing had rewritten the
            // column yet.
            $query->withEffectiveStatus($request->integer('status_id'));
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

        $page = $query->paginate($request->integer('per_page', 15));

        $response = $this->paginated($page, 'Warranty records retrieved');

        // Counted in SQL over the whole scoped set, NOT over $page->items().
        // The screen used to tally the rows it had been handed and label the
        // result "Active (page)", so the headline figures changed as you paged
        // and never described the business.
        return $response->setData(
            (object) ($response->getData(true) + ['summary' => $this->summaryFor($request)])
        );
    }

    /**
     * Active / expired / claimed totals for the current filters.
     *
     * Deliberately ignores any status filter: these counts are what the status
     * filter is chosen FROM, so narrowing to "Expired" must not reduce the other
     * two to zero. Every other filter still applies, so the numbers describe the
     * set the user is actually looking at.
     *
     * @return array<string,int>
     */
    private function summaryFor(Request $request): array
    {
        $base = fn () => WarrantyRecord::query()
            ->forUser($request->user())
            ->when($request->filled('customer_id'),
                fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('product_id'),
                fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('serial_number'), fn ($q) => $q->whereRaw(
                'LOWER(TRIM(serial_number)) = ?',
                [strtolower(trim($request->string('serial_number')))]
            ))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate(
                'warranty_end_date', '>=', $request->string('date_from')
            ));

        $active  = (clone $base())->withEffectiveStatus(WarrantyRecord::STATUS_ACTIVE)->count();
        $expired = (clone $base())->withEffectiveStatus(WarrantyRecord::STATUS_EXPIRED)->count();
        $claimed = (clone $base())->withEffectiveStatus(WarrantyRecord::STATUS_CLAIMED)->count();

        return [
            'active'  => $active,
            'expired' => $expired,
            'claimed' => $claimed,
            // Summed rather than counted separately so the three states and the
            // total can never disagree on screen.
            'total'   => $active + $expired + $claimed,
        ];
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
        // Answers about the UNIT, not only about a warranty record. Searching
        // warranty_records alone meant every unit still in the warehouse came
        // back 404 — true, and useless to someone holding the thing.
        //
        // 200 in every case, including an unknown serial: "we have never seen
        // this number" is an answer the panel should render plainly, not an
        // error box.
        $lookup = app(SerialLookupService::class)->find($serial);

        $payload = $lookup->toArray();
        $customer = $payload['customer_warranty'];

        return response()->json($payload + [
            'success' => true,
            'found'   => $lookup->state !== \App\Support\SerialLookup::UNKNOWN,
            // Kept for the claim button, which asks exactly one question.
            'is_claimable' => (bool) ($customer['is_claimable'] ?? false),
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
    /**
     * The name matching the DERIVED status.
     *
     * The `status` relation points at whatever the stored column says, so a
     * lapsed record would have come back labelled "Active" beside an
     * effective status of Expired — the payload contradicting itself.
     */
    private function statusName(WarrantyRecord $w): ?string
    {
        $effective = $w->effective_status_id;

        if ($effective === (int) $w->status_id) {
            return $w->status?->status_name;
        }

        return match ($effective) {
            WarrantyRecord::STATUS_ACTIVE  => 'Active',
            WarrantyRecord::STATUS_EXPIRED => 'Expired',
            WarrantyRecord::STATUS_CLAIMED => 'Claimed',
            default                        => $w->status?->status_name,
        };
    }

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
            // The DERIVED state, not the stored column. A record whose cover
            // ended last week is expired the moment it is read, whether or not
            // `warranties:expire` has run since. stored_status_id is kept
            // alongside so a reader can still see what the column says.
            'status_name'             => $this->statusName($w),
            'status_id'               => $w->effective_status_id,
            'stored_status_id'        => $w->status_id,
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
