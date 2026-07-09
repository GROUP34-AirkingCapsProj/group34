<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use App\Support\RealtimeBroadcaster;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BudgetAllocationController extends Controller
{
    use ApiResponse;

    public function __construct(private BudgetService $budgetService)
    {
    }

    public function index(Request $request)
    {
        $query = BudgetAllocation::with(['location', 'category', 'creator'])
            ->orderBy('period_start', 'desc');

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->input('verification_status'));
        }
        if ($request->input('active') == 1) {
            $today = today();
            $query->where('period_start', '<=', $today)
                  ->where('period_end', '>=', $today);
        }

        if ($request->input('paginate', 'true') === 'false') {
            $budgets = $query->get()->map(fn($b) => $this->withAvailable($b));
            return $this->success($budgets, 'Budget allocations retrieved');
        }

        $perPage = $request->input('per_page', 20);
        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(fn($b) => $this->withAvailable($b));
        return $this->paginated($paginated, 'Budget allocations retrieved');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_id'  => 'required|exists:locations,location_id',
                'category_id'  => 'nullable|exists:category_lookup,category_id',
                'period_label' => 'required|string|max:100',
                'period_start' => 'required|date|before:period_end',
                'period_end'   => 'required|date|after:period_start',
                'total_budget' => 'required|numeric|min:0.01',
                'notes'        => 'nullable|string',
            ]);

            // A budget for the same location + exact period + category is not a
            // new allocation — it tops up the existing one (added to its total,
            // journaled as an adjustment). This re-opens verification since the
            // amount an auditor vouched for has changed.
            $existing = BudgetAllocation::where('location_id', $validated['location_id'])
                ->whereDate('period_start', $validated['period_start'])
                ->whereDate('period_end', $validated['period_end'])
                ->when(
                    array_key_exists('category_id', $validated) && $validated['category_id'] !== null,
                    fn ($q) => $q->where('category_id', $validated['category_id']),
                    fn ($q) => $q->whereNull('category_id')
                )
                ->first();

            if ($existing) {
                $added   = (float) $validated['total_budget'];
                $newTotal = (float) $existing->total_budget + $added;
                $this->budgetService->adjustTotal(
                    $existing,
                    $newTotal,
                    $request->user()?->user_id,
                    sprintf('Top-up: added ₱%s to the %s budget (now ₱%s)',
                        number_format($added, 2), $existing->period_label, number_format($newTotal, 2))
                );
                $existing->refresh()->load(['location', 'category']);
                return $this->success($this->withAvailable($existing),
                    'Budget topped up — added to the existing allocation for this period');
            }

            $validated['committed'] = 0;
            $validated['spent']     = 0;
            $validated['created_by'] = $request->user()?->user_id;

            $budget = BudgetAllocation::create($validated);
            $budget->load(['location', 'category']);

            return $this->success($this->withAvailable($budget), 'Budget allocation created', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function show($id)
    {
        $budget = BudgetAllocation::with(['location', 'category'])->find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        return $this->success($this->withAvailable($budget), 'Budget allocation retrieved');
    }

    public function available($id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        return $this->success([
            'budget_id'           => $budget->budget_id,
            'total_budget'        => (float) $budget->total_budget,
            'committed'           => (float) $budget->committed,
            'spent'               => (float) $budget->spent,
            'available'           => $budget->available,
            'verification_status' => $budget->verification_status,
            'verification_note'   => $budget->verification_note,
        ], 'Budget availability retrieved');
    }

    /** Immutable ledger for a budget — the "where did the money go?" drill-down. */
    public function transactions(Request $request, $id)
    {
        $budget = BudgetAllocation::withTrashed()->find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        $txns = BudgetTransaction::where('budget_id', $id)
            ->with([
                'purchaseRequest' => fn ($q) => $q->withTrashed()->select('pr_id', 'pr_number'),
                'purchaseOrder:po_id,po_number',
                'performer:user_id,first_name,last_name,username',
            ])
            ->orderByDesc('txn_id')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($txns, 'Budget transactions retrieved');
    }

    /** Auditor verifies a budget — required before any PR can draw from it. */
    public function verify(Request $request, $id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }
        if ((int) $budget->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot verify a budget you created', 422);
        }
        if ($budget->verification_status === 'verified') {
            return $this->success($this->withAvailable($budget), 'Budget already verified');
        }

        $budget->update([
            'verification_status' => 'verified',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $request->input('note'),
        ]);

        RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'verification_changed', [
            'budget_id' => $budget->budget_id,
        ]);

        return $this->success($this->withAvailable($budget->fresh()->load(['location', 'category', 'verifier'])), 'Budget verified');
    }

    /** Auditor rejects a budget with a required reason — stays unusable until re-verified. */
    public function reject(Request $request, $id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }
        if ((int) $budget->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot reject a budget you created', 422);
        }

        try {
            $validated = $request->validate(['reason' => 'required|string|max:500']);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $budget->update([
            'verification_status' => 'rejected',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $validated['reason'],
        ]);

        RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'verification_changed', [
            'budget_id' => $budget->budget_id,
        ]);

        return $this->success($this->withAvailable($budget->fresh()->load(['location', 'category', 'verifier'])), 'Budget rejected');
    }

    public function update(Request $request, $id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        try {
            $validated = $request->validate([
                'location_id'  => 'sometimes|exists:locations,location_id',
                'category_id'  => 'nullable|exists:category_lookup,category_id',
                'period_label' => 'sometimes|string|max:100',
                'period_start' => 'sometimes|date',
                'period_end'   => 'sometimes|date',
                'total_budget' => 'sometimes|numeric|min:0.01',
                'notes'        => 'nullable|string',
            ]);

            if (array_key_exists('total_budget', $validated)) {
                $newTotal = (float) $validated['total_budget'];
                unset($validated['total_budget']);
                try {
                    $this->budgetService->adjustTotal($budget, $newTotal, $request->user()?->user_id);
                } catch (\InvalidArgumentException $e) {
                    return $this->error($e->getMessage(), 422);
                }
                $budget->refresh(); // adjustTotal mutates a locked re-fetch; sync this instance
            }

            $budget->update($validated);
            $budget->load(['location', 'category']);

            return $this->success($this->withAvailable($budget), 'Budget allocation updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function destroy($id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        if ((float) $budget->committed > 0) {
            return $this->error(sprintf(
                'This budget still has ₱%s committed to open purchase requests. Complete or archive those PRs first.',
                number_format((float) $budget->committed, 2)
            ), 422);
        }

        $budget->delete();
        return $this->success(null, 'Budget allocation deleted');
    }

    public function archived(Request $request)
    {
        $query = BudgetAllocation::onlyTrashed()->with(['location', 'category', 'creator']);

        if ($request->filled('search')) {
            $query->where('period_label', 'like', '%' . $request->input('search') . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived budget allocations retrieved'
        );
    }

    public function restore($id)
    {
        $budget = BudgetAllocation::onlyTrashed()->find($id);
        if (! $budget) {
            return $this->notFound('Archived budget allocation not found');
        }
        $budget->restore();
        return $this->success($this->withAvailable($budget->fresh()->load(['location', 'category', 'creator'])), 'Budget allocation restored successfully');
    }

    public function forceDestroy($id)
    {
        $budget = BudgetAllocation::onlyTrashed()->find($id);
        if (! $budget) {
            return $this->notFound('Archived budget allocation not found');
        }

        if (BudgetTransaction::where('budget_id', $budget->budget_id)->exists()) {
            return $this->error(
                'This budget has transaction history and cannot be permanently deleted. Keep it archived for the audit trail.',
                422
            );
        }

        $budget->forceDelete();
        return $this->success(null, 'Budget allocation permanently deleted');
    }

    private function withAvailable(BudgetAllocation $b): array
    {
        $data = $b->toArray();
        $data['available']   = $b->available;
        $data['created_by']  = $b->creator ? [
            'first_name' => $b->creator->first_name,
            'last_name'  => $b->creator->last_name,
            'username'   => $b->creator->username,
        ] : null;
        return $data;
    }
}
