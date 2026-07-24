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
        $query = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand']);

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('set_name', 'like', "%{$search}%")
                  ->orWhere('set_code', 'like', "%{$search}%");
            });
        }

        $sets = $query->orderBy('set_name')->get();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(fn (ProductSet $s) => $this->present($s))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $set = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->present($set)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);

        $set = DB::transaction(function () use ($validated) {
            $set = ProductSet::create([
                'set_code'        => $validated['set_code'],
                'set_name'        => $validated['set_name'],
                'description'     => $validated['description'] ?? null,
                'brand_id'        => $validated['brand_id'] ?? null,
                'capacity_rating' => $validated['capacity_rating'] ?? null,
                'variant'         => $validated['variant'] ?? null,
                'is_active'       => $validated['is_active'] ?? true,
                'created_by'      => Auth::id(),
                'updated_by'      => Auth::id(),
            ]);

            $this->syncLines($set, $validated['lines'] ?? []);

            return $set;
        });

        return response()->json([
            'success' => true,
            'message' => 'Set created.',
            'data'    => $this->present($set->fresh(['lines.product', 'lines.issuableUnit', 'brand'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $set       = ProductSet::findOrFail($id);
        $validated = $this->validatePayload($request, $id);

        DB::transaction(function () use ($set, $validated) {
            // Keyed on presence, not on value. Filtering out nulls would make
            // "clear this field" indistinguishable from "field omitted", so an
            // admin could never null out brand_id to correct a wrong assignment —
            // and brand_id feeds the set-matching index.
            $changes = ['updated_by' => Auth::id()];

            foreach (['set_code', 'set_name', 'description', 'brand_id', 'capacity_rating', 'variant', 'is_active'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field];
                }
            }

            $set->update($changes);

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
            'data'    => $this->present($set->fresh(['lines.product', 'lines.issuableUnit', 'brand'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        ProductSet::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Set archived.']);
    }

    /**
     * Suggest sets for a chosen appliance, ranked by how well they match.
     * Capacity is the anchor: a set that declares a capacity different from the
     * appliance's is never offered, because a 1TR BOQ on a 3TR unit is wrong.
     * Brand and variant refine the ranking, but a set that leaves them null is
     * treated as "applies to any", which is how generic BOQs are authored.
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
        ]);

        $product = \App\Models\Product::with('applianceDetail')->find($validated['product_id']);
        $detail  = $product?->applianceDetail;

        if (! $detail) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $norm = fn (?string $v) => $v === null ? null : strtolower(preg_replace('/\s+/', '', $v));

        $wantBrand    = $detail->brand_id !== null ? (int) $detail->brand_id : null;
        $wantCapacity = $norm($detail->capacity_rating);
        $wantVariant  = $norm($detail->variant);

        $sets = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand'])
            ->where('is_active', true)
            ->get()
            ->filter(function (ProductSet $set) use ($norm, $wantCapacity) {
                $setCapacity = $norm($set->capacity_rating);
                // Null capacity on the set = applies to any capacity.
                return $setCapacity === null || $wantCapacity === null || $setCapacity === $wantCapacity;
            })
            ->map(function (ProductSet $set) use ($norm, $wantBrand, $wantCapacity, $wantVariant) {
                $score = 0;
                if ($wantCapacity !== null && $norm($set->capacity_rating) === $wantCapacity) {
                    $score += 4;
                }
                if ($wantBrand !== null && $set->brand_id !== null && (int) $set->brand_id === $wantBrand) {
                    $score += 2;
                }
                if ($wantVariant !== null && $norm($set->variant) === $wantVariant) {
                    $score += 1;
                }

                return ['set' => $set, 'score' => $score];
            })
            ->sortByDesc(fn ($row) => $row['score'])
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(function ($row) {
                return $this->present($row['set']) + ['match_score' => $row['score']];
            })->all(),
        ]);
    }

    private function validatePayload(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'set_code'        => [
                $ignoreId === null ? 'required' : 'sometimes',
                'string', 'max:60',
                // Deliberately NOT scoped to whereNull('deleted_at'). The database
                // index on set_code is a plain unique across all rows, and MySQL
                // cannot express a partial index; a composite with deleted_at would
                // be worse, since MySQL treats NULLs as distinct and would then let
                // two ACTIVE sets share a code. So an archived code stays reserved,
                // and the caller gets a clean 422 instead of the raw 500 that a
                // soft-delete-aware rule would produce on insert.
                Rule::unique('product_sets', 'set_code')->ignore($ignoreId, 'set_id'),
            ],
            'set_name'        => [$ignoreId === null ? 'required' : 'sometimes', 'string', 'max:191'],
            'description'     => 'nullable|string|max:2000',
            'brand_id'        => 'nullable|integer|exists:brand_lookup,brand_id',
            'capacity_rating' => 'nullable|string|max:60',
            'variant'         => 'nullable|string|max:100',
            'is_active'       => 'nullable|boolean',
            'lines'                     => 'sometimes|array',
            'lines.*.product_id'        => 'required|integer|exists:products,product_id',
            'lines.*.quantity'          => 'required|numeric|min:0.001',
            'lines.*.issuable_unit_id'  => 'nullable|integer|exists:product_issuable_units,issuable_unit_id',
            'lines.*.spec_note'         => 'nullable|string|max:191',
            'lines.*.sort_order'        => 'nullable|integer',
        ]);
    }

    private function syncLines(ProductSet $set, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $set->lines()->create([
                'product_id'       => $line['product_id'],
                'quantity'         => $line['quantity'],
                'issuable_unit_id' => $line['issuable_unit_id'] ?? null,
                'spec_note'        => $line['spec_note'] ?? null,
                'sort_order'       => $line['sort_order'] ?? ($i + 1),
            ]);
        }
    }

    private function present(ProductSet $set): array
    {
        return [
            'set_id'          => (int) $set->set_id,
            'set_code'        => $set->set_code,
            'set_name'        => $set->set_name,
            'description'     => $set->description,
            'brand_id'        => $set->brand_id !== null ? (int) $set->brand_id : null,
            'brand_name'      => $set->brand?->brand_name,
            'capacity_rating' => $set->capacity_rating,
            'variant'         => $set->variant,
            'is_active'       => (bool) $set->is_active,
            'set_price'       => $set->totalPrice(),
            'lines'           => $set->lines->map(fn ($line) => [
                'set_line_id'        => (int) $line->set_line_id,
                'product_id'         => (int) $line->product_id,
                'product_name'       => $line->product?->product_name,
                'product_type'       => $line->product?->product_type,
                'quantity'           => (float) $line->quantity,
                'issuable_unit_id'   => $line->issuable_unit_id !== null ? (int) $line->issuable_unit_id : null,
                'unit_label'         => $line->issuableUnit?->label,
                'quantity_in_pieces' => $line->quantityInPieces(),
                'spec_note'          => $line->spec_note,
                'sort_order'         => (int) $line->sort_order,
                'unit_price'         => (float) ($line->product?->unit_price ?? 0),
                'line_total'         => round((float) $line->quantity * (float) ($line->product?->unit_price ?? 0), 2),
            ])->all(),
        ];
    }
}
