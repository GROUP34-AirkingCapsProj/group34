<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcTypeLookup;
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

            // ---- the original becomes its side --------------------------
            // product_id is untouched, deliberately. See the class docblock.
            $original->unit_price = $prices[$keepAs];
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
                'model_name'      => $validated['model_name'] ?? $original->product_name,
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
