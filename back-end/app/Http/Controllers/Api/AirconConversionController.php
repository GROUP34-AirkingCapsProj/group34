<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcTypeLookup;
use App\Models\AirconModelUnit;
use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Services\AirconModelComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a legacy single-row aircon — one product carrying the whole
 * machine, unit_role 'complete' — into the indoor/outdoor pair the model
 * catalogue expects.
 *
 * WHY THIS IS A TOOL AND NOT A MIGRATION
 * --------------------------------------
 * The obvious implementation is: create two fresh products, compose a model
 * from them, archive the original. That is wrong, and dangerously so. The
 * live catalogue's aircon rows are pointed at by item_serial, inventory,
 * purchase_order_details and issuance_details, none of which this tool knows
 * how to rewrite. Archiving the original strands every one of those
 * references against a row nobody can transact on again.
 *
 * So the original product row IS one of the two sides. Its product_id never
 * changes; what changes is its price, its unit_role and its model_code. Only
 * the PARTNER side is newly created. Everything already pointing at the
 * original keeps pointing at a live product, and the pair is what the
 * composed AirconModel binds together. That invariant is what
 * AirconConversionTest::test_existing_references_survive_the_conversion
 * exists to hold; if it ever fails, this endpoint must be withdrawn.
 *
 * THE PRICE GUARD
 * ---------------
 * A conversion that does not preserve the total is a silent repricing of the
 * catalogue. The two sides must sum, to the centavo, to what the single row
 * cost, or nothing happens. This is checked before any write.
 *
 * THE COST SPLIT
 * --------------
 * products.cost_price is NOT NULL DEFAULT 0.00, so leaving it out of the
 * partner's create() does not leave it undecided - it writes a hard zero, and
 * nothing in the system ever fills it in afterwards (only ProductController
 * and PendingProductController write the column; receiving only reads it).
 * A zero there is not cosmetic: LocationPerformanceReport values that stock
 * at nil, InventorySummaryReport prints a zero unit cost,
 * DashboardAnalyticsService reports a 100% margin on it, and a warranty claim
 * against the unit is valued at nothing.
 *
 * So the cost is split by the same ratio the user just declared for the
 * price, and BOTH sides are written - the original's cost has to come down
 * too, or the pair together double-counts what the single row cost.
 *
 * WHAT THAT DOES TO STOCK ALREADY ON HAND - READ THIS
 * ---------------------------------------------------
 * This conversion does not touch `inventory`, and that has a consequence
 * worth stating outright rather than discovering from a report.
 *
 * Existing on-hand rows stay on the original product, and they still count
 * WHOLE MACHINES - four of them means four complete aircons in that
 * location. After the split that same quantity is valued at only one side's
 * cost, and the partner holds no stock to make up the difference. So the
 * valuation of stock that already existed drops, at the instant of
 * conversion, with no physical movement behind it. Two consumers show it:
 * LocationPerformanceReport (SUM(quantity_on_hand * cost_price)) and
 * InventorySummaryReport (the unit-cost column). On live product 7, four
 * units at 20,612.35 read as 82,449.40 before a 50/50 split and 41,224.72
 * after.
 *
 * The double-count argument above therefore only holds for stock received
 * AFTER the conversion, when each side is stocked on its own row. For stock
 * that predates it, the old single-row figure happened to be the right
 * total.
 *
 * Mirroring the inventory rows onto the partner would balance the report and
 * was rejected anyway. Those rows record a physical count. Writing four
 * outdoor rows would assert that four outdoor units are sitting in that
 * location when nobody has counted them, and that claim does not stay in a
 * report - it feeds availability and pick lists. Inventing stock to make a
 * number look right is a worse failure than a valuation that is honestly
 * wrong and visibly so. The remedy is a physical count of both sides, which
 * is a warehouse operation, not something a catalogue tool may fabricate.
 * The modal tells the user this before they commit.
 *
 * One consequence of following the declared ratio: a side declared at a
 * price of zero takes a cost of zero with it. A shop pricing the whole set
 * on the compressor and the indoor head at nothing gets an indoor unit
 * costing nothing. That is not the defect this section is about - that one
 * was a zero nobody asked for, written because the column defaults to it.
 * This zero is the ratio the user typed, carried through faithfully, and
 * flooring it would invent a cost the user did not declare. Pinned by
 * test_a_side_declared_at_zero_takes_a_zero_cost_with_it.
 *
 * The composed model goes through AirconModelComposer like every other
 * composition — the piece-count rule has exactly one home.
 */
class AirconConversionController extends Controller
{
    public function __construct(private readonly AirconModelComposer $composer)
    {
    }

    /**
     * POST /api/products/{product}/convert-to-two-piece
     *
     * Route-model bound rather than a raw id: the binding carries the
     * SoftDeletes scope, so an archived product 404s instead of being
     * quietly rewritten and composed into a live model.
     */
    public function convert(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'indoor.model_code'  => 'required|string|max:100',
            'indoor.unit_price'  => 'required|numeric|min:0',
            'outdoor.model_code' => 'required|string|max:100',
            'outdoor.unit_price' => 'required|numeric|min:0',
            'keep_as'            => 'required|in:indoor,outdoor',
            'model_name'         => 'nullable|string|max:191',
        ]);

        $keepAs      = $validated['keep_as'];
        $partnerRole = $keepAs === 'indoor' ? 'outdoor' : 'indoor';

        $prices = [
            'indoor'  => round((float) $validated['indoor']['unit_price'], 2),
            'outdoor' => round((float) $validated['outdoor']['unit_price'], 2),
        ];
        $codes = [
            'indoor'  => $validated['indoor']['model_code'],
            'outdoor' => $validated['outdoor']['model_code'],
        ];

        $model = DB::transaction(function () use ($product, $keepAs, $partnerRole, $prices, $codes, $validated) {
            // Re-read under a row lock. Two conversions of the same product
            // racing would otherwise each see unit_role 'complete', pass the
            // guard below, and produce two partners for one original.
            $original = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            $detail = ApplianceDetail::where('product_id', $original->product_id)->first();

            if ($detail === null) {
                $this->reject('product', 'Only appliance products can be converted; this product has no appliance details.');
            }

            if ($detail->unit_role !== 'complete') {
                $this->reject('product', sprintf(
                    'Only a complete unit can be split into a pair. This product is already the %s side of one.',
                    $detail->unit_role ?? 'unknown'
                ));
            }

            // A window type has nothing to pair with. Only rejected when the
            // type is actually known to be one-piece — a legacy row with no
            // ac_type_id at all is exactly the row this tool is for, so a
            // missing type must not block it.
            $acType = $detail->ac_type_id !== null
                ? AcTypeLookup::find($detail->ac_type_id)
                : null;

            if ($acType !== null && ! $acType->is_two_piece) {
                $this->reject('product', sprintf(
                    'A %s aircon is a single piece and cannot be split.',
                    $acType->ac_type_name
                ));
            }

            // A product already composed into a model must not be split.
            //
            // aircon_model_units is unique on (aircon_model_id, product_id),
            // not on product_id, so nothing at the database level stops the
            // same product belonging to two models. And the piece-count rule
            // reads unit_role live out of appliance_details - it is never
            // snapshotted onto the line, and it only runs on write. So
            // flipping this product's role from 'complete' to 'indoor' would
            // silently invalidate any one-piece model already built on it:
            // the model stays in the catalogue violating the invariant, and
            // the next person to edit it gets a 422 they did not cause.
            //
            // Refusing is the conservative reading and easy to relax later.
            // Archived models count too: archiving leaves the unit lines in
            // place and restore() re-validates nothing, so an archived model
            // is a broken composition waiting to come back.
            $memberOf = AirconModelUnit::query()
                ->where('aircon_model_units.product_id', $original->product_id)
                ->join('aircon_models', 'aircon_models.aircon_model_id', '=', 'aircon_model_units.aircon_model_id')
                ->orderBy('aircon_models.aircon_model_id')
                ->first(['aircon_models.model_name', 'aircon_models.deleted_at']);

            if ($memberOf !== null) {
                $this->reject('product', sprintf(
                    'This product is already part of the aircon model "%s"%s. Splitting it would leave that model built on a unit whose role changed underneath it. Point that model at a different unit first - replacing it rather than removing it, since a one-piece model cannot be left with no unit at all.',
                    $memberOf->model_name,
                    $memberOf->deleted_at !== null ? ' (archived)' : ''
                ));
            }

            // ---- the price guard, before any write ----------------------
            $originalPrice = round((float) $original->unit_price, 2);
            $splitTotal    = round($prices['indoor'] + $prices['outdoor'], 2);

            if (abs($splitTotal - $originalPrice) >= 0.005) {
                $this->reject('unit_price', sprintf(
                    'The split must preserve the price. Indoor %s + outdoor %s = %s, but this product costs %s.',
                    number_format($prices['indoor'], 2),
                    number_format($prices['outdoor'], 2),
                    number_format($splitTotal, 2),
                    number_format($originalPrice, 2)
                ));
            }

            // ---- the cost split, by the declared price ratio -------------
            // In centavos, with the second side taken as the REMAINDER rather
            // than computed on its own: two independently rounded halves can
            // each be right to the centavo and still fail to sum.
            $costs = $this->splitCost(
                (float) $original->cost_price,
                $prices['indoor'],
                $originalPrice
            );

            // ---- the original becomes its side --------------------------
            // product_id is untouched, deliberately. See the class docblock.
            $original->unit_price = $prices[$keepAs];
            $original->cost_price = $costs[$keepAs];
            $original->save();

            $detail->unit_role  = $keepAs;
            $detail->model_code = $codes[$keepAs];
            $detail->save();

            // ---- the partner is the only new row ------------------------
            $partner = Product::create([
                'category_id'        => $original->category_id,
                'product_code'       => $this->derivePartnerCode($original->product_code, $partnerRole),
                'product_name'       => $this->derivePartnerName($original->product_name, $partnerRole),
                'product_type'       => 'appliance',
                'description'        => $original->description,
                'unit_price'         => $prices[$partnerRole],
                // Explicit, not omitted: the column is NOT NULL DEFAULT 0.00,
                // so omitting it silently prices this unit's cost at nothing.
                'cost_price'         => $costs[$partnerRole],
                'status_id'          => $original->status_id,
                'recommended_stocks' => $original->recommended_stocks,
                'safety_stock'       => $original->safety_stock,
                'max_stock_level'    => $original->max_stock_level,
            ]);

            ApplianceDetail::create([
                'product_id'             => $partner->product_id,
                'brand_id'               => $detail->brand_id,
                'model_code'             => $codes[$partnerRole],
                'capacity_rating'        => $detail->capacity_rating,
                'variant'                => $detail->variant,
                'ac_type_id'             => $detail->ac_type_id,
                'unit_role'              => $partnerRole,
                'color'                  => $detail->color,
                'warranty_period_months' => $detail->warranty_period_months,
                'warranty_value'         => $detail->warranty_value,
                'warranty_unit'          => $detail->warranty_unit,
                'is_serialized'          => $detail->is_serialized,
            ]);

            $indoorId  = $keepAs === 'indoor'  ? $original->product_id : $partner->product_id;
            $outdoorId = $keepAs === 'outdoor' ? $original->product_id : $partner->product_id;

            // Through the composer, not around it: the piece-count rule has
            // one home, and this is the second entry point it was written for.
            return $this->composer->create([
                'model_name'      => $validated['model_name'] ?? $this->deriveModelName($original->product_name, $detail),
                'brand_id'        => $detail->brand_id,
                'ac_type_id'      => $detail->ac_type_id,
                'capacity_rating' => $detail->capacity_rating,
                'variant'         => $detail->variant,
                'is_two_piece'    => true,
                'is_active'       => true,
            ], [
                ['product_id' => $indoorId,  'quantity' => 1],
                ['product_id' => $outdoorId, 'quantity' => 1],
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product converted to a two-piece pair.',
            // The same shape the models page already consumes, so the client
            // can drop the result straight into its list.
            'data'    => AirconModelController::present(
                $model->loadMissing(['units.product.applianceDetail', 'brand', 'acType'])
            ),
        ], 201);
    }

    /**
     * Splits a cost between the two sides in the ratio the caller declared for
     * the price, in centavos, so the halves sum to the original exactly.
     *
     * When the original price is zero there is no ratio to read - and the
     * price guard has already established that both declared prices are zero
     * too, since they have to sum to it. An even split is then the only
     * division that treats the two sides alike and still sums, with the odd
     * centavo going to the outdoor side. Carrying the whole cost on the kept
     * side was the alternative and was rejected: which side is kept is a UI
     * choice, and what a machine cost must not depend on it.
     *
     * @return array{indoor:float,outdoor:float}
     */
    private function splitCost(float $originalCost, float $indoorPrice, float $originalPrice): array
    {
        $costCentavos     = (int) round($originalCost * 100);
        $priceCentavos    = (int) round($originalPrice * 100);
        $indoorPriceCents = (int) round($indoorPrice * 100);

        $indoorCentavos = $priceCentavos > 0
            ? (int) round($costCentavos * $indoorPriceCents / $priceCentavos)
            : intdiv($costCentavos, 2);

        return [
            'indoor'  => $indoorCentavos / 100,
            'outdoor' => ($costCentavos - $indoorCentavos) / 100,
        ];
    }

    /**
     * A default name for the composed model.
     *
     * Not simply the product name. The original product keeps its own name -
     * it appears on printed paperwork, so this tool does not get to change it
     * - and a model carrying that same string would sit beside its own member
     * product in the same UI reading identically. Capacity and variant are
     * what distinguish the pair, and unlike the product name the model name is
     * a brand-new record with no history to protect.
     *
     * Falls back to a "(Two-Piece)" suffix when there is no capacity or
     * variant to add, so the default is never byte-identical to the product.
     */
    private function deriveModelName(?string $productName, ApplianceDetail $detail): string
    {
        $base  = $productName !== null && $productName !== '' ? $productName : 'Aircon';
        $parts = array_values(array_filter([$detail->capacity_rating, $detail->variant]));

        $name = $parts !== []
            ? $base . ' ' . implode(' ', $parts)
            : $base . ' (Two-Piece)';

        return substr($name, 0, 191);
    }

    /**
     * A free product_code for the partner, derived from the original's.
     *
     * The original keeps its own code untouched — labels, barcodes and
     * printed paperwork already carry it, and this tool is not entitled to
     * invalidate them.
     *
     * products.product_code is varchar(50) and unique across trashed rows
     * too, so the stem is trimmed to fit the suffix and a numeric tail is
     * added until the code is free.
     */
    private function derivePartnerCode(?string $base, string $role): string
    {
        $base   = $base !== null && $base !== '' ? $base : 'AC';
        $suffix = $role === 'indoor' ? '-IDU' : '-ODU';

        $candidate = substr($base, 0, 50 - strlen($suffix)) . $suffix;

        $n = 2;
        while (Product::withTrashed()->where('product_code', $candidate)->exists()) {
            $tail      = $suffix . '-' . $n;
            $candidate = substr($base, 0, 50 - strlen($tail)) . $tail;
            $n++;
        }

        return $candidate;
    }

    private function derivePartnerName(?string $base, string $role): string
    {
        $label = $role === 'indoor' ? ' (Indoor Unit)' : ' (Outdoor Unit)';
        $base  = $base !== null && $base !== '' ? $base : 'Aircon';

        return substr($base, 0, 255 - strlen($label)) . $label;
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
