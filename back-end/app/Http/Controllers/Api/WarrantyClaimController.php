<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WarrantyClaimException;
use App\Http\Controllers\Controller;
use App\Models\WarrantyClaim;
use App\Services\WarrantyClaimService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesUserLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarrantyClaimController extends Controller
{
    use ApiResponse;
    use ResolvesUserLocation;

    public function __construct(private WarrantyClaimService $service)
    {
    }

    public function index(Request $request)
    {
        $query = WarrantyClaim::with(['customer', 'product', 'status', 'warranty'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('claim_type')) {
            $query->where('claim_type', $request->string('claim_type'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q
                ->where('claim_number', 'like', "%{$s}%")
                ->orWhere('serial_number', 'like', "%{$s}%"));
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)), 'Warranty claims retrieved');
    }

    public function init(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id'   => 'required|integer|exists:customers,customer_id',
                'serial_number' => 'required|string|max:100',
                'claim_type'    => ['required', Rule::in(['repair', 'replacement'])],
                'notes'         => 'nullable|string|max:1000',
                'location_id'   => 'nullable|integer|exists:locations,location_id',
            ]);

            $locationId = $this->resolveLocationId($request);
            if ($locationId === null) {
                return $this->locationErrorResponse();
            }
            $validated['location_id'] = $locationId;

            $claim = $this->service->initClaim($validated);
            return $this->success($this->show($claim->claim_id, true), 'Claim started.', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function addItem(Request $request, int $claimId)
    {
        try {
            $validated = $request->validate([
                'barcode'  => 'required|string|max:191',
                'quantity' => 'nullable|numeric|min:0.001',
            ]);
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->addItem($claim, $validated);
            return $this->success($this->show($claimId, true), 'Item added.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function removeItem(int $claimId, int $detailId)
    {
        try {
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->removeItem($claim, $detailId);
            return $this->success($this->show($claimId, true), 'Item removed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function complete(int $claimId)
    {
        try {
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->completeClaim($claim);
            return $this->success($this->show($claimId, true), 'Warranty claim completed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** @return array|\Illuminate\Http\JsonResponse */
    public function show(int $claimId, bool $asArray = false)
    {
        $claim = WarrantyClaim::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'customer', 'product', 'location', 'status', 'warranty', 'createdByUser',
        ])->find($claimId);

        if (! $claim) {
            return $asArray ? [] : $this->notFound('Claim not found');
        }
        $payload = $this->format($claim);
        return $asArray ? $payload : $this->success($payload, 'Claim retrieved');
    }

    public function downloadSlip(int $claimId)
    {
        $claim = WarrantyClaim::with([
            'details.product', 'customer', 'location', 'status', 'warranty',
        ])->find($claimId);

        if (! $claim) {
            return $this->notFound('Claim not found');
        }

        $filename = "{$claim->claim_number}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claim) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['WARRANTY CLAIM SLIP']);
            fputcsv($h, ['Claim Number', $claim->claim_number]);
            fputcsv($h, ['Type',         ucfirst($claim->claim_type)]);
            fputcsv($h, ['Status',       $claim->status?->status_name ?? '']);
            fputcsv($h, ['Date',         $claim->completed_at?->toDateString() ?? $claim->created_at?->toDateString()]);
            fputcsv($h, ['Location',     $claim->location?->location_name ?? '']);
            fputcsv($h, []);
            fputcsv($h, ['Customer',      $claim->customer?->customer_name ?? '']);
            fputcsv($h, ['Contact',       $claim->customer?->contact_number ?? '']);
            fputcsv($h, ['Claimed Serial',$claim->serial_number ?? '']);
            fputcsv($h, ['Problem/Notes', $claim->notes ?? '']);
            fputcsv($h, []);
            fputcsv($h, ['#', 'Product Code', 'Product Name', 'Serial', 'Qty', 'Unit', 'Cost']);
            foreach ($claim->details as $i => $d) {
                fputcsv($h, [
                    $i + 1,
                    $d->product?->product_code ?? '',
                    $d->product?->product_name ?? '',
                    $d->serial_number ?? '',
                    $d->quantity_issued,
                    $d->quantity_unit ?? '',
                    number_format($d->line_cost, 2),
                ]);
            }
            fputcsv($h, []);
            fputcsv($h, ['Total Parts Cost (internal)', number_format($claim->parts_cost, 2)]);
            fclose($h);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function format(WarrantyClaim $c): array
    {
        return [
            'claim_id'      => $c->claim_id,
            'claim_number'  => $c->claim_number,
            'claim_type'    => $c->claim_type,
            'status_name'   => $c->status?->status_name,
            'notes'         => $c->notes,
            'serial_number' => $c->serial_number,
            'parts_cost'    => $c->parts_cost,
            'completed_at'  => $c->completed_at?->toDateTimeString(),
            'location_name' => $c->location?->location_name,
            'warranty' => $c->warranty ? [
                'warranty_id'       => $c->warranty->warranty_id,
                'warranty_end_date' => $c->warranty->warranty_end_date?->format('Y-m-d'),
                'status_id'         => $c->warranty->status_id,
            ] : null,
            'customer' => $c->customer ? [
                'customer_id'   => $c->customer->customer_id,
                'customer_name' => $c->customer->customer_name,
                'customer_type' => $c->customer->customer_type,
                'company_name'  => $c->customer->company_name,
                'contact_number'=> $c->customer->contact_number,
            ] : null,
            'items' => $c->details->map(fn ($d) => [
                'detail_id'          => $d->claim_detail_id,
                'product_id'         => $d->product_id,
                'product_name'       => $d->product?->product_name,
                'product_code'       => $d->product?->product_code,
                'serial_number'      => $d->serial_number,
                'quantity_issued'    => $d->quantity_issued,
                'quantity_in_pieces' => $d->quantity_in_pieces,
                'quantity_unit'      => $d->quantity_unit,
                'line_cost'          => $d->line_cost,
            ])->values()->all(),
        ];
    }
}
