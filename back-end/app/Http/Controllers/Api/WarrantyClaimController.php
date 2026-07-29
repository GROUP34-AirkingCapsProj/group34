<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WarrantyClaimException;
use App\Http\Controllers\Controller;
use App\Models\WarrantyClaim;
use App\Models\Inventory;
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
            ->forUser($request->user())
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
                'customer_id'   => 'nullable|integer|exists:customers,customer_id',
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
            return $this->success($this->payloadForUser($claim->claim_id, $request), 'Claim started.', 201);
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
                'quantity' => 'nullable|integer|min:1',
            ]);
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->addItem($claim, $validated);
            return $this->success($this->payloadForUser($claimId, $request), 'Item added.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function addCustomOrderLine(Request $request, int $claimId)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|integer|exists:products,product_id',
                'quantity_in_pieces' => 'required|integer|min:1',
            ]);
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->addCustomOrderLine($claim, (int) $validated['product_id'], (int) $validated['quantity_in_pieces']);
            return $this->success($this->payloadForUser($claimId, $request), 'Product added to warranty order.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function addSetOrder(Request $request, int $claimId)
    {
        try {
            $validated = $request->validate([
                'set_id' => 'required|integer|exists:product_sets,set_id',
                'quantity' => 'required|integer|min:1',
            ]);
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->addSetOrder($claim, (int) $validated['set_id'], (int) $validated['quantity']);
            return $this->success($this->payloadForUser($claimId, $request), 'Set added to warranty order.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function updateOrderLine(Request $request, int $claimId, int $detailId)
    {
        try {
            $validated = $request->validate(['quantity_in_pieces' => 'required|integer|min:1']);
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->updateOrderLine($claim, $detailId, (int) $validated['quantity_in_pieces']);
            return $this->success($this->payloadForUser($claimId, $request), 'Warranty order updated.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function removeOrderLine(Request $request, int $claimId, int $detailId)
    {
        try {
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->removeOrderLine($claim, $detailId);
            return $this->success($this->payloadForUser($claimId, $request), 'Product removed from warranty order.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function removeItem(Request $request, int $claimId, int $detailId)
    {
        try {
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->removeItem($claim, $detailId);
            return $this->success($this->payloadForUser($claimId, $request), 'Item removed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function complete(Request $request, int $claimId)
    {
        try {
            $claim = WarrantyClaim::forUser($request->user())->findOrFail($claimId);
            $this->service->completeClaim($claim);
            return $this->success($this->payloadForUser($claimId, $request), 'Warranty claim completed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $claimId)
    {
        $claim = $this->findVisibleClaim($claimId, $request);
        if (! $claim) {
            return $this->notFound('Claim not found');
        }
        return $this->success($this->format($claim), 'Claim retrieved');
    }

    public function downloadSlip(Request $request, int $claimId)
    {
        $claim = WarrantyClaim::with([
            'details.product', 'customer', 'location', 'status', 'warranty',
        ])->forUser($request->user())->find($claimId);

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
            fputcsv($h, ['#', 'Model Code', 'Product Name', 'Serial', 'Qty', 'Unit', 'Cost']);
            foreach ($claim->details as $i => $d) {
                fputcsv($h, [
                    $i + 1,
                    $d->product?->applianceDetail?->model_code ?? '',
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

    public function receiptPdf(Request $request, int $claimId)
    {
        $claim = $this->findVisibleClaim($claimId, $request);

        if (! $claim) {
            return $this->notFound('Claim not found');
        }

        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('warranty.claim_receipt', [
            'claim' => $claim,
            'company' => [
                'name' => \App\Models\SystemSetting::companyName(),
                'address' => \App\Models\SystemSetting::companyAddress(),
                'email' => \App\Models\SystemSetting::companyEmail(),
                'phone' => \App\Models\SystemSetting::companyPhone(),
            ],
        ])->setPaper($paperSize);

        $filename = $claim->claim_number . '.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function format(WarrantyClaim $c): array
    {
        $availability = Inventory::where('location_id', $c->location_id)
            ->whereIn('product_id', $c->details->pluck('product_id')->unique())
            ->pluck('available_quantity', 'product_id');
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
            'product' => $c->product ? [
                'product_id'   => $c->product->product_id,
                'product_name' => $c->product->product_name,
                'product_code' => $c->product->applianceDetail?->model_code ?? $c->product->product_code,
                'brand_name'   => $c->product->applianceDetail?->brand?->brand_name,
            ] : null,
            'warranty' => $c->warranty ? [
                'warranty_id'       => $c->warranty->warranty_id,
                'warranty_start_date' => $c->warranty->warranty_start_date?->format('Y-m-d'),
                'warranty_end_date' => $c->warranty->warranty_end_date?->format('Y-m-d'),
                'warranty_duration_label' => $c->warranty->warranty_duration_label,
                'issuance_id'       => $c->warranty->issuance_id,
                'issuance_reference'=> $c->warranty->issuance?->dr_number,
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
                'model_code'         => $d->product?->applianceDetail?->model_code,
                'product_code'       => $d->product?->applianceDetail?->model_code ?? $d->product?->product_code,
                'serial_number'      => $d->serial_number,
                'quantity_requested' => (int) $d->quantity_requested,
                'quantity_issued'    => $d->quantity_issued,
                'quantity_remaining' => max(0, (int) $d->quantity_requested - (int) $d->quantity_issued),
                'available_quantity' => (int) ($availability[$d->product_id] ?? 0),
                'has_sufficient_stock' => (int) ($availability[$d->product_id] ?? 0) >=
                    max(0, (int) $d->quantity_requested - (int) $d->quantity_issued),
                'is_fulfilled'       => (int) $d->quantity_issued >= (int) $d->quantity_requested,
                'quantity_in_pieces' => $d->quantity_in_pieces,
                'quantity_unit'      => $d->quantity_unit,
                'pieces_per_package' => $d->product?->getPiecesPerPackage() ?? 1,
                'package_unit'       => $d->product?->consumableDetail?->unit?->unit_name ?? 'unit',
                'line_source'        => $d->line_source,
                'unit_cost'          => $d->unit_cost,
                'line_cost'          => $d->line_cost,
            ])->values()->all(),
        ];
    }

    private function payloadForUser(int $claimId, Request $request): array
    {
        $claim = $this->findVisibleClaim($claimId, $request);
        return $claim ? $this->format($claim) : [];
    }

    private function findVisibleClaim(int $claimId, Request $request): ?WarrantyClaim
    {
        return WarrantyClaim::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'customer', 'product.applianceDetail.brand', 'location', 'status',
            'warranty.issuance', 'createdByUser',
        ])->forUser($request->user())->find($claimId);
    }
}
