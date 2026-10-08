<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AirconModel;
use App\Models\AirconModelUnit;
use App\Services\AirconModelComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD over the aircon model catalogue.
 *
 * Deliberately thin: every write goes through AirconModelComposer, which owns
 * the piece-count rule. Task 15's conversion tool composes models too, and a
 * rule enforced at one of two entry points is not a rule — so this controller
 * validates shapes and presents rows, and decides nothing about what makes a
 * composition legal. ValidationException from the composer is left to
 * propagate: Laravel renders it as a 422 carrying the `lines` key already.
 */
class AirconModelController extends Controller
{
    /**
     * Eager-loads for every read path.
     *
     * `units.product.applianceDetail`, not `units.product`:
     * AirconModelUnit::unitRole() reads through product->applianceDetail and
     * BOTH relations are lazy, so stopping at the product still costs a query
     * per unit. A 20-model listing would pay 40 extra queries for the roles
     * alone.
     *
     * @var list<string>
     */
    private const RELATIONS = ['units.product.applianceDetail', 'brand', 'acType'];

    public function __construct(private readonly AirconModelComposer $composer)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = AirconModel::with(self::RELATIONS);

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('model_name', 'like', "%{$search}%")
                  ->orWhere('capacity_rating', 'like', "%{$search}%")
                  ->orWhere('variant', 'like', "%{$search}%");
            });
        }

        $models = $query->orderBy('model_name')->get();

        return response()->json([
            'success' => true,
            'data'    => $models->map(fn (AirconModel $m) => self::present($m))->all(),
        ]);
    }

    /**
     * Route-model bound rather than findOrFail($id): the binding carries the
     * SoftDeletes scope, so an archived model 404s here and — more importantly
     * — on update() and destroy() below. The composer does not refuse to write
     * to a trashed model; keeping it unreachable is this binding's job.
     */
    public function show(AirconModel $airconModel): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => self::present($airconModel->loadMissing(self::RELATIONS)),
        ]);
    }

    /**
     * Soft-deleted models only — mirrors index() but scoped to onlyTrashed().
     *
     * A distinct endpoint rather than a flag on index(), for the same reason
     * ProductSetController::archived() is one: `active_only=false` widens the
     * is_active filter, but the SoftDeletes global scope still hides trashed
     * rows, and these are two independent axes. Without this the archived rows
     * are unreachable through the API entirely — and restore() below could only
     * be called with an id the UI had no way to learn.
     */
    public function archived(Request $request): JsonResponse
    {
        $query = AirconModel::onlyTrashed()->with(self::RELATIONS);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('model_name', 'like', "%{$search}%")
                  ->orWhere('capacity_rating', 'like', "%{$search}%")
                  ->orWhere('variant', 'like', "%{$search}%");
            });
        }

        $models = $query->orderByDesc('deleted_at')->get();

        return response()->json([
            'success' => true,
            'data'    => $models->map(fn (AirconModel $m) => self::present($m))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        $model = $this->composer->create(
            $this->attributesFrom($validated),
            $validated['lines']
        );

        return response()->json([
            'success' => true,
            'message' => 'Aircon model created.',
            // loadMissing, not load: the composer already returns the model
            // fresh with units, products, brand and acType attached, so load()
            // would re-run all four to add the one relation they lack.
            // loadMissing resolves the nested path and fetches only the missing
            // segment: measured at 1 query here where load() issued 5.
            'data'    => self::present($model->loadMissing(self::RELATIONS)),
        ], 201);
    }

    public function update(Request $request, AirconModel $airconModel): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        // null, not [] — the composer reads null as "leave the lines alone",
        // and an empty array as "this model now has no lines", which the
        // piece-count rule then rejects. A payload that omits `lines` means
        // the former.
        $model = $this->composer->update(
            $airconModel,
            $this->attributesFrom($validated),
            array_key_exists('lines', $validated) ? $validated['lines'] : null
        );

        return response()->json([
            'success' => true,
            'message' => 'Aircon model updated.',
            'data'    => self::present($model->loadMissing(self::RELATIONS)),
        ]);
    }

    public function destroy(AirconModel $airconModel): JsonResponse
    {
        $airconModel->delete();

        return response()->json(['success' => true, 'message' => 'Aircon model archived.']);
    }

    /**
     * Takes a raw id rather than a bound instance: the binding's SoftDeletes
     * scope hides exactly the rows this method exists to reach.
     */
    public function restore(int $id): JsonResponse
    {
        $model = AirconModel::onlyTrashed()->findOrFail($id);

        $model->restore();

        return response()->json([
            'success' => true,
            'message' => 'Aircon model restored.',
            'data'    => self::present($model->loadMissing(self::RELATIONS)),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'model_name'      => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'brand_id'        => 'nullable|integer|exists:brand_lookup,brand_id',
            'ac_type_id'      => 'nullable|integer|exists:ac_type_lookup,ac_type_id',
            'capacity_rating' => 'nullable|string|max:20',
            'variant'         => 'nullable|string|max:100',
            'is_two_piece'    => 'sometimes|boolean',
            'is_active'       => 'sometimes|boolean',

            'lines'              => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            // `distinct` is defence in depth. The composer rejects repeated
            // products too (it has to — the conversion tool never comes through
            // here), but a clean 422 out of validation beats leaning on the
            // service for a malformed payload.
            'lines.*.product_id' => 'required|integer|distinct|exists:products,product_id',
            'lines.*.quantity'   => 'nullable|integer|min:1|max:65535',
        ], [
            // `distinct` short-circuits the composer, so the composer's wording
            // never reaches the client. Laravel's default — "The
            // lines.0.product_id field has a duplicate value." — names an array
            // path the builder UI does not show and, worse, does not tell the
            // user what to do instead. Restate the composer's advice here so
            // the same guidance arrives whichever layer catches it.
            'lines.*.product_id.distinct' => 'Each product can appear only once — raise its quantity instead of adding a second line.',
        ]);
    }

    /**
     * The model's own columns out of a validated payload — everything but the
     * lines. Only keys the caller actually sent survive, so update() stays a
     * partial edit.
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function attributesFrom(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'model_name', 'brand_id', 'ac_type_id',
            'capacity_rating', 'variant',
            'is_two_piece', 'is_active',
        ]));
    }

    /**
     * The wire shape of one model.
     *
     * Static and public because AirconConversionController returns a composed
     * model too, and the models page must not have to tell the two responses
     * apart. Presentation reads nothing off $this.
     *
     * @return array<string,mixed>
     */
    public static function present(AirconModel $model): array
    {
        return [
            'aircon_model_id' => (int) $model->aircon_model_id,
            'model_name'      => $model->model_name,
            'brand_id'        => $model->brand_id !== null ? (int) $model->brand_id : null,
            'brand_name'      => $model->brand?->brand_name,
            'ac_type_id'      => $model->ac_type_id !== null ? (int) $model->ac_type_id : null,
            'ac_type_name'    => $model->acType?->ac_type_name,
            'capacity_rating' => $model->capacity_rating,
            'variant'         => $model->variant,
            'is_two_piece'    => (bool) $model->is_two_piece,
            'is_active'       => (bool) $model->is_active,
            'label'           => $model->label(),
            'total_price'     => $model->totalPrice(),
            'created_at'      => $model->created_at,
            'updated_at'      => $model->updated_at,
            'deleted_at'      => $model->deleted_at,
            'units'           => $model->units->map(fn (AirconModelUnit $unit) => [
                'aircon_model_unit_id' => (int) $unit->aircon_model_unit_id,
                'product_id'           => (int) $unit->product_id,
                'product_name'         => $unit->product?->product_name,
                'product_code'         => $unit->product?->product_code,
                'unit_price'           => (float) ($unit->product?->unit_price ?? 0),
                'unit_role'            => $unit->unitRole(),
                'quantity'             => (int) $unit->quantity,
                'sort_order'           => (int) $unit->sort_order,
                // A member product archived after the model was composed. The
                // line still names it (product() uses withTrashed()); this flag
                // is what lets the UI say so instead of showing a live row.
                'is_archived'          => (bool) $unit->product?->trashed(),
            ])->values()->all(),
        ];
    }
}
