<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetAllocation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BudgetAllocationController extends Controller
{
    use ApiResponse;

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
            'budget_id'    => $budget->budget_id,
            'total_budget' => (float) $budget->total_budget,
            'committed'    => (float) $budget->committed,
            'spent'        => (float) $budget->spent,
            'available'    => $budget->available,
        ], 'Budget availability retrieved');
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
