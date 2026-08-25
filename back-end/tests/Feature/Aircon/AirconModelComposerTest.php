<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\AirconModelUnit;
use App\Services\AirconModelComposer;
use Illuminate\Validation\ValidationException;

/**
 * The piece-count rule, and the composition it guards.
 *
 * The rule lives in the service and not the controller because Task 15's
 * conversion tool composes models too; these tests exercise it at the level
 * both entry points share.
 */
class AirconModelComposerTest extends AirconTestCase
{
    private AirconModelComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->composer = new AirconModelComposer();
    }

    /** @return array<string,mixed> */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ], $overrides);
    }

    private function line(int $productId, int $quantity = 1): array
    {
        return ['product_id' => $productId, 'quantity' => $quantity];
    }

    // ---------------------------------------------------------------- create

    public function test_a_standard_split_composes(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $model = $this->composer->create($this->attributes(), [
            $this->line($indoor->product_id),
            $this->line($outdoor->product_id),
        ]);

        $this->assertTrue($model->exists);
        $this->assertCount(2, $model->units);
        $this->assertSame(
            ['indoor', 'outdoor'],
            $model->units->map(fn (AirconModelUnit $u) => $u->unitRole())->all()
        );
        $this->assertSame([0, 1], $model->units->pluck('sort_order')->all());
        $this->assertSame(auth()->id(), (int) $model->created_by);
        $this->assertSame(auth()->id(), (int) $model->updated_by);
    }

    public function test_a_multi_split_composes_with_quantities(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $model = $this->composer->create($this->attributes(), [
            $this->line($indoor->product_id, 3),
            $this->line($outdoor->product_id, 1),
        ]);

        $this->assertSame(
            [3, 1],
            $model->fresh('units')->units->pluck('quantity')->all(),
            'a 3:1 multi-split must keep its quantities'
        );
    }

    public function test_a_two_piece_model_without_an_outdoor_line_is_rejected(): void
    {
        $indoor = $this->unitProduct('indoor');

        $this->expectException(ValidationException::class);

        $this->composer->create($this->attributes(), [
            $this->line($indoor->product_id),
        ]);
    }

    public function test_a_two_piece_model_without_an_indoor_line_is_rejected(): void
    {
        $outdoor = $this->unitProduct('outdoor');

        try {
            $this->composer->create($this->attributes(), [
                $this->line($outdoor->product_id),
            ]);
            $this->fail('a two-piece model with no indoor unit must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertStringContainsString('indoor', $e->errors()['lines'][0]);
        }
    }

    public function test_a_one_piece_model_takes_exactly_one_complete_line(): void
    {
        $complete = $this->unitProduct('complete');

        $model = $this->composer->create(
            $this->attributes(['is_two_piece' => false, 'ac_type_id' => $this->windowType()->ac_type_id]),
            [$this->line($complete->product_id)]
        );

        $this->assertCount(1, $model->units);
        $this->assertSame('complete', $model->units->first()->unitRole());
        $this->assertFalse($model->is_two_piece);
    }

    public function test_a_one_piece_model_carrying_an_indoor_line_is_rejected(): void
    {
        $indoor = $this->unitProduct('indoor');

        try {
            $this->composer->create(
                $this->attributes(['is_two_piece' => false]),
                [$this->line($indoor->product_id)]
            );
            $this->fail('a one-piece model whose unit is an indoor half must be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('complete', $e->errors()['lines'][0]);
        }
    }

    public function test_a_one_piece_model_with_two_lines_is_rejected(): void
    {
        $complete = $this->unitProduct('complete');
        $spare    = $this->unitProduct('complete');

        $this->expectException(ValidationException::class);

        $this->composer->create(
            $this->attributes(['is_two_piece' => false]),
            [$this->line($complete->product_id), $this->line($spare->product_id)]
        );
    }

    public function test_a_rejected_composition_leaves_no_model_behind(): void
    {
        $before = AirconModel::withTrashed()->count();

        try {
            $this->composer->create($this->attributes(), [
                $this->line($this->unitProduct('indoor')->product_id),
            ]);
            $this->fail('composition should have been rejected');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(
            $before,
            AirconModel::withTrashed()->count(),
            'a rejected composition must not leave a half-built model behind, archived or otherwise'
        );
    }

    public function test_total_price_reflects_quantity_times_member_price(): void
    {
        $model = $this->composer->create($this->attributes(), [
            $this->line($this->unitProduct('indoor', 5000.00)->product_id, 3),
            $this->line($this->unitProduct('outdoor', 20000.00)->product_id, 1),
        ]);

        $this->assertSame(35000.00, $model->totalPrice());
    }

    // ---------------------------------------------------------------- update

    private function split(): AirconModel
    {
        return $this->composer->create($this->attributes(), [
            $this->line($this->unitProduct('indoor')->product_id),
            $this->line($this->unitProduct('outdoor')->product_id),
        ]);
    }

    public function test_update_replaces_the_unit_lines_wholesale(): void
    {
        $model = $this->split();
        $oldIds = $model->units->pluck('product_id')->all();

        $newIndoor  = $this->unitProduct('indoor');
        $newOutdoor = $this->unitProduct('outdoor');

        $updated = $this->composer->update($model, ['variant' => 'Non-Inverter'], [
            $this->line($newIndoor->product_id, 2),
            $this->line($newOutdoor->product_id),
        ]);

        $this->assertSame('Non-Inverter', $updated->variant);
        $this->assertSame(
            [$newIndoor->product_id, $newOutdoor->product_id],
            $updated->units->pluck('product_id')->all()
        );
        $this->assertSame([2, 1], $updated->units->pluck('quantity')->all());
        $this->assertSame(
            0,
            AirconModelUnit::where('aircon_model_id', $model->aircon_model_id)
                ->whereIn('product_id', $oldIds)->count(),
            'the replaced lines must be gone, not merely reordered'
        );
    }

    public function test_flipping_a_two_line_model_to_one_piece_is_rejected(): void
    {
        $model = $this->split();

        try {
            // No lines sent. A naive check validates the incoming set — empty,
            // or worse, waved through — instead of the two lines that would
            // actually still be in force after the flip.
            $this->composer->update($model, ['is_two_piece' => false]);
            $this->fail('flipping a two-line model to one-piece must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertTrue(
            $model->fresh()->is_two_piece,
            'the rejected flip must not have been persisted'
        );
        $this->assertCount(2, $model->fresh('units')->units);
    }

    public function test_update_with_null_lines_leaves_the_existing_lines_intact(): void
    {
        $model    = $this->split();
        $expected = $model->units->pluck('product_id')->all();

        $updated = $this->composer->update($model, ['model_name' => 'Renamed ' . uniqid()]);

        $this->assertSame($expected, $updated->units->pluck('product_id')->all());
        $this->assertSame(auth()->id(), (int) $updated->updated_by);
    }
}
