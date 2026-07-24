<?php

namespace Tests\Feature\Sets;

use App\Models\ProductIssuableUnit;
use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class SetLineConversionTest extends SetTestCase
{
    private function makeSet(string $name = 'Piping'): ProductSet
    {
        return ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => $name]);
    }

    private function makeUnit(int $productId, string $label, float $factor): ProductIssuableUnit
    {
        return ProductIssuableUnit::create([
            'product_id'        => $productId,
            'unit_id'           => (int) DB::table('unit_lookup')->min('unit_id'),
            'label'             => $label,
            'conversion_factor' => $factor,
            'is_whole_package'  => true,
            'sort_order'        => 1,
        ]);
    }

    public function test_line_quantity_converts_to_pieces_via_issuable_unit(): void
    {
        $product = $this->makeConsumable();
        $roll    = $this->makeUnit($product->product_id, 'Roll', 50);

        $line = $this->makeSet()->lines()->create([
            'product_id'       => $product->product_id,
            'quantity'         => 2,
            'issuable_unit_id' => $roll->issuable_unit_id,
        ]);

        $this->assertSame(100, $line->fresh()->load('issuableUnit')->quantityInPieces());
    }

    public function test_line_without_issuable_unit_is_already_pieces(): void
    {
        $product = $this->makeConsumable();

        $line = $this->makeSet('Bolts')->lines()->create([
            'product_id' => $product->product_id,
            'quantity'   => 7,
        ]);

        $this->assertSame(7, $line->fresh()->quantityInPieces());
    }

    public function test_fractional_quantity_rounds_up_to_whole_pieces(): void
    {
        $product = $this->makeConsumable();

        // BOQ line "Angle bar 1.5L" with no conversion unit — half a length still
        // means taking a whole one off the rack.
        $line = $this->makeSet('Angle bar')->lines()->create([
            'product_id' => $product->product_id,
            'quantity'   => 1.5,
        ]);

        $this->assertSame(2, $line->fresh()->quantityInPieces());
    }

    /**
     * Regression: quantity is a decimal(10,3), so many ordinary values are binary
     * fractions whose product overshoots. A bare ceil() turned 1.1 x 100 into 111
     * pieces instead of 110, silently over-issuing stock on every expansion.
     *
     * @dataProvider exactMultiples
     */
    public function test_exact_multiples_do_not_overshoot(float $quantity, float $factor, int $expected): void
    {
        $product = $this->makeConsumable();
        $unit    = $this->makeUnit($product->product_id, 'Unit', $factor);

        $line = $this->makeSet()->lines()->create([
            'product_id'       => $product->product_id,
            'quantity'         => $quantity,
            'issuable_unit_id' => $unit->issuable_unit_id,
        ]);

        $this->assertSame(
            $expected,
            $line->fresh()->load('issuableUnit')->quantityInPieces(),
            "{$quantity} x {$factor} must be exactly {$expected} pieces"
        );
    }

    public static function exactMultiples(): array
    {
        return [
            '1.1 x 100' => [1.1, 100, 110],
            '0.14 x 100' => [0.14, 100, 14],
            '0.56 x 50' => [0.56, 50, 28],
            '2.18 x 100' => [2.18, 100, 218],
            '1.12 x 25' => [1.12, 25, 28],
            '2 x 50'    => [2.0, 50, 100],
        ];
    }

    public function test_a_corrupt_conversion_factor_falls_back_to_pieces(): void
    {
        $product = $this->makeConsumable();
        $unit    = $this->makeUnit($product->product_id, 'Broken', 0);

        $line = $this->makeSet()->lines()->create([
            'product_id'       => $product->product_id,
            'quantity'         => 4,
            'issuable_unit_id' => $unit->issuable_unit_id,
        ]);

        $this->assertSame(4, $line->fresh()->load('issuableUnit')->quantityInPieces());
    }
}
