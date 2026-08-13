<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductSetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductSet::with(['lines.product.consumableDetail', 'brands']);

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('capacity_rating', 'like', "%{$search}%")
                  ->orWhere('variant', 'like', "%{$search}%");
            });
        }

        $sets = $query->orderBy('capacity_rating')->get();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(fn (ProductSet $s) => $this->present($s))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $set = ProductSet::with(['lines.product.consumableDetail', 'brands'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->present($set)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);

        $set = DB::transaction(function () use ($validated) {
            $set = ProductSet::create([
                'capacity_rating' => $validated['capacity_rating'] ?? null,
                'variant'         => $validated['variant'] ?? null,
                'is_active'       => $validated['is_active'] ?? true,
                'created_by'      => Auth::id(),
                'updated_by'      => Auth::id(),
            ]);

            $set->brands()->sync($validated['brand_ids'] ?? []);

            $this->syncLines($set, $validated['lines'] ?? []);

            return $set;
        });

        return response()->json([
            'success' => true,
            'message' => 'Set created.',
            'data'    => $this->present($set->fresh(['lines.product.consumableDetail', 'brands'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $set       = ProductSet::findOrFail($id);
        $validated = $this->validatePayload($request, $id);

        DB::transaction(function () use ($set, $validated) {
            $changes = ['updated_by' => Auth::id()];

            foreach (['capacity_rating', 'variant', 'is_active'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field];
                }
            }

            $set->update($changes);

            // Brands are replaced wholesale — only when the caller sent the field.
            if (array_key_exists('brand_ids', $validated)) {
                $set->brands()->sync($validated['brand_ids'] ?? []);
            }

            // Lines are replaced wholesale — the builder UI always posts the full list.
            if (array_key_exists('lines', $validated)) {
                // ProductSetLine has no SoftDeletes trait, so delete() here is
                // already a hard delete — there is no forceDelete() to call.
                $set->lines()->delete();
                $this->syncLines($set, $validated['lines']);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Set updated.',
            'data'    => $this->present($set->fresh(['lines.product.consumableDetail', 'brands'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        ProductSet::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Set archived.']);
    }

    /** Soft-deleted sets only — mirrors index() but scoped to onlyTrashed(). */
    public function archived(Request $request): JsonResponse
    {
        $query = ProductSet::onlyTrashed()->with(['lines.product.consumableDetail', 'brands']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('capacity_rating', 'like', "%{$search}%")
                  ->orWhere('variant', 'like', "%{$search}%");
            });
        }

        $sets = $query->orderByDesc('deleted_at')->get();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(fn (ProductSet $s) => $this->present($s))->all(),
        ]);
    }

    /**
     * Restore an archived set. Sets carry no unique code, so restore only flips
     * deleted_at and never risks a conflict.
     */
    public function restore(int $id): JsonResponse
    {
        $set = ProductSet::onlyTrashed()->findOrFail($id);

        $set->restore();

        return response()->json([
            'success' => true,
            'message' => 'Set restored.',
            'data'    => $this->present($set->fresh(['lines.product.consumableDetail', 'brands'])),
        ]);
    }

    private function validatePayload(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'brand_ids'       => [$ignoreId === null ? 'required' : 'sometimes', 'array', 'min:1'],
            // Scoped to LIVE brands. A bare `exists:` queries the table raw and
            // ignores soft deletes, but brands() filters them on read — so a
            // deleted id passed validation, landed in the pivot, and then went
            // missing from the response the caller got back. Worse, the hidden
            // row survives: restoring the brand would silently reattach it to
            // sets nobody edited. Rejecting it says so instead.
            'brand_ids.*'     => ['integer', Rule::exists('brand_lookup', 'brand_id')->whereNull('deleted_at')],
            'capacity_rating' => 'nullable|string|max:60',
            'variant'         => 'nullable|string|max:100',
            'is_active'       => 'nullable|boolean',
            'lines'                     => 'sometimes|array',
            'lines.*.product_id'        => 'required|integer|exists:products,product_id',
            'lines.*.quantity'          => 'required|integer|min:1',
            'lines.*.spec_note'         => 'nullable|string|max:191',
            'lines.*.sort_order'        => 'nullable|integer',
        ]);
    }

    private function syncLines(ProductSet $set, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $set->lines()->create([
                'product_id' => $line['product_id'],
                'quantity'   => $line['quantity'],
                'spec_note'  => $line['spec_note'] ?? null,
                'sort_order' => $line['sort_order'] ?? ($i + 1),
            ]);
        }
    }

    private function present(ProductSet $set): array
    {
        return [
            'set_id'          => (int) $set->set_id,
            'capacity_rating' => $set->capacity_rating,
            'variant'         => $set->variant,
            'is_active'       => (bool) $set->is_active,
            'label'           => $set->label(),
            'brands'          => $set->brands->map(fn ($b) => [
                'brand_id'   => (int) $b->brand_id,
                'brand_name' => $b->brand_name,
            ])->values()->all(),
            'brand_names'     => $set->brands->pluck('brand_name')->implode(', '),
            'set_price'       => $set->totalPrice(),
            'lines'           => $set->lines->map(fn ($line) => [
                'set_line_id'        => (int) $line->set_line_id,
                'product_id'         => (int) $line->product_id,
                'product_name'       => $line->product?->product_name,
                'product_type'       => $line->product?->product_type,
                'quantity'           => (int) round((float) $line->quantity),   // pieces
                'pieces_per_package' => (int) ($line->product?->pieces_per_package ?? 0),
                'package_unit'       => $line->product?->consumableDetail?->package_unit,
                'spec_note'          => $line->spec_note,
                'sort_order'         => (int) $line->sort_order,
                'unit_price'         => (float) ($line->product?->unit_price ?? 0),
                'line_total'         => round(
                    (float) $line->quantity * ($line->product?->getPricePerPiece() ?? 0),
                    2
                ),
            ])->values()->all(),
        ];
    }
}
