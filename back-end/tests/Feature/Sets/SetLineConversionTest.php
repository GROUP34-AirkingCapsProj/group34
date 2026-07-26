<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class SetLineConversionTest extends SetTestCase
{
    private function makeSet(): ProductSet
    {
        return ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
    }

    public function test_whole_line_quantity_is_already_pieces(): void
    {
        $product = $this->makeConsumable();

        $line = $this->makeSet()->lines()->create([
            'product_id' => $product->product_id,
            'quantity'   => 7,
        ]);

        $this->assertSame(7, $line->fresh()->quantityInPieces());
    }

    /**
     * A set line now stores its quantity directly in pieces. A fractional value
     * (e.g. "1.5 lengths" of angle bar) still means taking a whole one off the
     * rack, so it rounds to the nearest whole piece.
     *
     * @dataProvider fractionalQuantities
     */
    public function test_fractional_quantity_rounds_to_whole_pieces(float $quantity, int $expected): void
    {
        $product = $this->makeConsumable();

        $line = $this->makeSet()->lines()->create([
            'product_id' => $product->product_id,
            'quantity'   => $quantity,
        ]);

        $this->assertSame(
            $expected,
            $line->fresh()->quantityInPieces(),
            "{$quantity} must round to {$expected} pieces"
        );
    }

    public static function fractionalQuantities(): array
    {
        return [
            '1.5 -> 2'  => [1.5, 2],
            '1.4 -> 1'  => [1.4, 1],
            '2.0 -> 2'  => [2.0, 2],
            '0.5 -> 1'  => [0.5, 1],
            '10.49 -> 10' => [10.49, 10],
        ];
    }
}
