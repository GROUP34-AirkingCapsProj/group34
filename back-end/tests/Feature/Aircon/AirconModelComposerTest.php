<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\AirconModelUnit;
use App\Services\AirconModelComposer;
use Illuminate\Support\Facades\DB;
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

        try {
            $this->composer->create($this->attributes(), [
                $this->line($indoor->product_id),
            ]);
            $this->fail('a two-piece model with no outdoor unit must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            // 'Missing: outdoor', not bare 'outdoor' — the sentence explaining
            // the rule names both sides, so matching the bare word would pass
            // for the no-indoor message too.
            $this->assertStringContainsString(
                'Missing: outdoor',
                $e->errors()['lines'][0],
                'the message must name what is missing, not just refuse'
            );
        }
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
            $this->assertStringContainsString('Missing: indoor', $e->errors()['lines'][0]);
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

        try {
            $this->composer->create(
                $this->attributes(['is_two_piece' => false]),
                [$this->line($complete->product_id), $this->line($spare->product_id)]
            );
            $this->fail('a one-piece model with two lines must be rejected');
        } catch (ValidationException $e) {
            // Rejected for the line COUNT — both products are complete units,
            // so a bare expectException here would also pass if the composer
            // refused for the wrong reason.
            $this->assertStringContainsString(
                'exactly one complete unit line; 2 were given',
                $e->errors()['lines'][0]
            );
        }
    }

    public function test_the_same_product_cannot_be_listed_twice(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        // Deduping the ids before the role check would let this through — both
        // roles are present — and syncLines() would then hit the
        // unique(aircon_model_id, product_id) index and 500. "One line per
        // product per model" is a composition constraint, so it is rejected
        // here, as a 422, not by the storage engine.
        try {
            $this->composer->create($this->attributes(), [
                $this->line($indoor->product_id),
                $this->line($outdoor->product_id),
                $this->line($indoor->product_id),
            ]);
            $this->fail('a product listed twice must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertStringContainsString(
                (string) $indoor->product_id,
                $e->errors()['lines'][0],
                'the message must name the offending product'
            );
        }
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
    }

    public function test_update_records_the_editor_not_the_author(): void
    {
        $model  = $this->split();
        $author = (int) $model->created_by;

        // A second user does the edit. Asserting updated_by === auth()->id()
        // while still acting as the author proves nothing — it is already true
        // from create() and would pass with the assignment deleted.
        $editor = $this->actingAsAdmin();
        $this->assertNotSame($author, $editor->user_id, 'the editor must be a different user');

        $updated = $this->composer->update($model, ['variant' => 'Non-Inverter']);

        $this->assertSame($editor->user_id, (int) $updated->updated_by);
        $this->assertSame($author, (int) $updated->created_by, 'an edit must not rewrite authorship');
    }

    public function test_a_lines_only_edit_still_advances_updated_at(): void
    {
        $model = $this->split();

        // Backdate without touching the model, so "advanced" is observable
        // regardless of the column's one-second resolution.
        AirconModel::whereKey($model->aircon_model_id)->update(['updated_at' => now()->subDay()]);

        $before = $model->fresh()->updated_at;

        // No attributes at all, and the same user who created it — so nothing
        // on aircon_models is dirty and save() would otherwise fire no UPDATE,
        // leaving "last modified" reporting the create time for a real edit.
        $updated = $this->composer->update($model, [], [
            $this->line($this->unitProduct('indoor')->product_id),
            $this->line($this->unitProduct('outdoor')->product_id),
        ]);

        $this->assertTrue(
            $updated->updated_at->greaterThan($before),
            'replacing the lines is a modification; updated_at must say so'
        );
    }

    public function test_update_locks_the_model_row_before_reading_its_lines(): void
    {
        $model = $this->split();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->composer->update($model, ['variant' => 'Non-Inverter']);

        $sql = array_map(fn (array $q) => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $lockAt = $this->firstMatch($sql, fn (string $q) => str_contains($q, 'aircon_models')
            && str_contains($q, 'for update'));
        $linesAt = $this->firstMatch($sql, fn (string $q) => str_contains($q, 'select')
            && str_contains($q, 'aircon_model_units'));

        $this->assertNotNull($lockAt, 'update() must take a row lock — without it two updates interleave');
        $this->assertNotNull($linesAt, 'the effective line set must be read from the database');
        $this->assertLessThan(
            $linesAt,
            $lockAt,
            'the lock must precede the read it protects, or the read is still unserialised'
        );
    }

    /** @param list<string> $haystack */
    private function firstMatch(array $haystack, callable $matches): ?int
    {
        foreach ($haystack as $i => $entry) {
            if ($matches($entry)) {
                return $i;
            }
        }

        return null;
    }

    // ----------------------------------------------------------- authorship

    public function test_create_ignores_a_caller_supplied_author(): void
    {
        $model = $this->composer->create(
            $this->attributes(['created_by' => 99999, 'updated_by' => 99999]),
            [
                $this->line($this->unitProduct('indoor')->product_id),
                $this->line($this->unitProduct('outdoor')->product_id),
            ]
        );

        $this->assertSame(auth()->id(), (int) $model->created_by, 'authorship must not be spoofable');
        $this->assertSame(auth()->id(), (int) $model->updated_by);
    }

    public function test_update_ignores_a_caller_supplied_author(): void
    {
        $model  = $this->split();
        $author = (int) $model->created_by;

        $editor = $this->actingAsAdmin();

        $updated = $this->composer->update($model, [
            'variant'    => 'Non-Inverter',
            'created_by' => 99999,
            'updated_by' => 99999,
        ]);

        $this->assertSame($author, (int) $updated->created_by);
        $this->assertSame($editor->user_id, (int) $updated->updated_by);
    }
}
