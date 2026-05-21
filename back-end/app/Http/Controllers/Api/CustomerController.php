<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Issuance;
use App\Models\WarrantyRecord;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use ApiResponse;

    // Status ID constants
    private const STATUS_CUSTOMER_ACTIVE   = 36;
    private const STATUS_WARRANTY_ACTIVE   = 33;
    private const STATUS_WARRANTY_EXPIRED  = 34;
    private const STATUS_WARRANTY_CLAIMED  = 35;

    // GET /api/customers
    public function index(Request $request)
    {
        $query = Customer::with('status')
                         ->withCount(['issuances', 'warrantyRecords'])
                         ->orderBy('customer_name');

        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(function ($q) use ($s) {
                $q->where('customer_name',    'like', "%{$s}%")
                  ->orWhere('customer_code',  'like', "%{$s}%")
                  ->orWhere('company_name',   'like', "%{$s}%")
                  ->orWhere('contact_number', 'like', "%{$s}%")
                  ->orWhere('email',          'like', "%{$s}%");
            });
        }

        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->string('customer_type'));
        }

        if ($request->boolean('include_archived')) {
            $query->withTrashed();
        }

        $perPage = $request->integer('per_page', 15);
        return $this->paginated(
            $query->paginate($perPage),
            'Customers retrieved successfully'
        );
    }

    // GET /api/customers/search?q=...
    // Lightweight search for issuance dropdowns (no pagination)
    public function search(Request $request)
    {
        $q = trim($request->string('q', ''));

        $customers = Customer::whereNull('deleted_at')
            ->where(function ($query) use ($q) {
                $query->where('customer_name',  'like', "%{$q}%")
                      ->orWhere('customer_code', 'like', "%{$q}%")
                      ->orWhere('company_name',  'like', "%{$q}%")
                      ->orWhere('contact_number','like', "%{$q}%");
            })
            ->orderByRaw("CASE WHEN customer_type = 'registered' THEN 0 ELSE 1 END")
            ->limit(20)
            ->get([
                'customer_id', 'customer_code', 'customer_name',
                'customer_type', 'company_name',
                'contact_number', 'email', 'address',
            ]);

        return $this->success($customers, 'Search results');
    }

    // GET /api/customers/{id}
    public function show($id)
    {
        $customer = Customer::with('status')
            ->withCount(['issuances', 'warrantyRecords'])
            ->find($id);

        if (! $customer) {
            return $this->notFound('Customer not found');
        }
        return $this->success($customer, 'Customer retrieved successfully');
    }

    // GET /api/customers/{id}/history
    // Full transaction history: all issuances with warranty status per item
    public function history($id)
    {
        $customer = Customer::find($id);
        if (! $customer) {
            return $this->notFound('Customer not found');
        }

        $issuances = Issuance::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'details.warrantyRecord.status',
            'location',
            'issuedByUser',
            'status',
        ])
        ->where('customer_id', $id)
        ->orderBy('issuance_date', 'desc')
        ->get()
        ->map(function ($iss) {
            $issuedBy = $iss->issuedByUser
                ? trim(($iss->issuedByUser->first_name ?? '')
                      . ' ' . ($iss->issuedByUser->last_name ?? ''))
                : '—';

            return [
                'issuance_id'      => $iss->issuance_id,
                'dr_number'        => $iss->dr_number,
                'issuance_date'    => $iss->issuance_date,
                'issuance_type'    => $iss->issuance_type,
                'location_name'    => $iss->location?->location_name,
                'issued_by_name'   => $issuedBy,
                'total_amount'     => $iss->total_amount,
                'discount_percent' => $iss->discount_percent,
                'status_name'      => $iss->status?->status_name,
                'items' => $iss->details->map(function ($d) {
                    $p  = $d->product;
                    $ad = $p?->applianceDetail;
                    $cd = $p?->consumableDetail;
                    $wr = $d->warrantyRecord;

                    $warrantyLabel = null;
                    if ($wr) {
                        if ($wr->status_id === self::STATUS_WARRANTY_CLAIMED) {
                            $warrantyLabel = 'Claimed';
                        } elseif ($wr->is_expired) {
                            $warrantyLabel = 'Expired';
                        } else {
                            $daysLeft = $wr->days_remaining;
                            $warrantyLabel = $daysLeft !== null
                                ? "Active ({$daysLeft} days left)"
                                : 'Active';
                        }
                    }

                    return [
                        'product_name'       => $p?->product_name,
                        'product_code'       => $p?->product_code,
                        'product_type'       => $p?->product_type,
                        'brand_name'         => $ad?->brand?->brand_name,
                        'capacity_rating'    => $ad?->capacity_rating,
                        'unit_name'          => $cd?->unit?->unit_name,
                        'serial_number'      => $d->serial_number,
                        'quantity_issued'    => $d->quantity_issued,
                        'quantity_unit'      => $d->quantity_unit,
                        'unit_price'         => $d->unit_price,
                        'subtotal'           => $d->subtotal,
                        'warranty_status'    => $warrantyLabel,
                        'warranty_end_date'  => $wr?->warranty_end_date,
                        'warranty_duration'  => $wr?->warranty_duration_label,
                    ];
                })->values(),
            ];
        });

        $activeWarranties = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'status',
        ])
        ->where('customer_id', $id)
        ->whereIn('status_id', [
            self::STATUS_WARRANTY_ACTIVE,
            self::STATUS_WARRANTY_EXPIRED,
        ])
        ->orderBy('warranty_end_date', 'asc')
        ->get()
        ->map(fn($w) => [
            'warranty_id'             => $w->warranty_id,
            'serial_number'           => $w->serial_number,
            'product_name'            => $w->product?->product_name,
            'brand_name'              => $w->product?->applianceDetail?->brand?->brand_name,
            'warranty_start_date'     => $w->warranty_start_date,
            'warranty_end_date'       => $w->warranty_end_date,
            'warranty_duration_label' => $w->warranty_duration_label,
            'days_remaining'          => $w->days_remaining,
            'is_expired'              => $w->is_expired,
            'status_name'             => $w->status?->status_name,
        ]);

        return $this->success([
            'customer'           => $customer,
            'issuances'          => $issuances,
            'warranty_records'   => $activeWarranties,
            'total_transactions' => $issuances->count(),
            'active_warranties'  => $activeWarranties
                                        ->where('is_expired', false)
                                        ->count(),
        ], 'Customer history retrieved');
    }

    // POST /api/customers
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name'  => 'required|string|max:255',
            'customer_type'  => ['required', Rule::in(['registered', 'walk_in'])],
            'customer_code'  => 'nullable|string|max:50|unique:customers,customer_code',
            'contact_number' => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:100',
            'company_name'   => 'nullable|string|max:255',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string|max:100',
            'province'       => 'nullable|string|max:100',
            'region'         => 'nullable|string|max:100',
            'tin'            => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'credit_limit'   => 'nullable|numeric|min:0',
        ]);

        if (empty($validated['customer_code'])) {
            $prefix = $validated['customer_type'] === 'registered' ? 'REG' : 'WLK';
            $validated['customer_code'] = $prefix . '-' . strtoupper(substr(uniqid(), -6));
        }

        $validated['status_id']          = self::STATUS_CUSTOMER_ACTIVE;
        $validated['outstanding_balance'] = 0;

        $customer = Customer::create($validated);
        $customer->load('status');

        return $this->success($customer, 'Customer created successfully', 201);
    }

    // PUT /api/customers/{id}
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (! $customer) {
            return $this->notFound('Customer not found');
        }

        $validated = $request->validate([
            'customer_name'  => 'sometimes|required|string|max:255',
            'customer_type'  => ['sometimes', Rule::in(['registered', 'walk_in'])],
            'customer_code'  => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'customer_code')
                    ->ignore($customer->customer_id, 'customer_id'),
            ],
            'contact_number' => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:100',
            'company_name'   => 'nullable|string|max:255',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string|max:100',
            'province'       => 'nullable|string|max:100',
            'region'         => 'nullable|string|max:100',
            'tin'            => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'credit_limit'   => 'nullable|numeric|min:0',
            'status_id'      => 'nullable|exists:status_lookup,status_id',
        ]);

        $customer->update($validated);
        $customer->load('status');

        return $this->success($customer, 'Customer updated successfully');
    }

    // DELETE /api/customers/{id} — soft delete
    public function destroy($id)
    {
        $customer = Customer::find($id);
        if (! $customer) {
            return $this->notFound('Customer not found');
        }
        $customer->delete();
        return $this->success(null, 'Customer archived');
    }

    // POST /api/customers/{id}/restore
    public function restore($id)
    {
        $customer = Customer::withTrashed()->find($id);
        if (! $customer) {
            return $this->notFound('Customer not found');
        }
        $customer->restore();
        return $this->success($customer, 'Customer restored');
    }

    // GET /api/customers/export
    public function export(Request $request)
    {
        $customers = Customer::with('status')
            ->withCount('issuances')
            ->when(
                $request->filled('customer_type'),
                fn($q) => $q->where('customer_type', $request->string('customer_type'))
            )
            ->orderBy('customer_name')
            ->get();

        $filename = 'customers-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($customers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Customer Code', 'Customer Name', 'Type',
                'Company', 'Contact', 'Email',
                'City', 'Province', 'TIN',
                'Total Transactions', 'Status', 'Created',
            ]);
            foreach ($customers as $c) {
                fputcsv($handle, [
                    $c->customer_code,
                    $c->customer_name,
                    $c->customer_type === 'registered' ? 'Registered' : 'Walk-in',
                    $c->company_name ?? '',
                    $c->contact_number ?? '',
                    $c->email ?? '',
                    $c->city ?? '',
                    $c->province ?? '',
                    $c->tin ?? '',
                    $c->issuances_count,
                    $c->status?->status_name ?? 'Active',
                    $c->created_at?->format('Y-m-d'),
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
