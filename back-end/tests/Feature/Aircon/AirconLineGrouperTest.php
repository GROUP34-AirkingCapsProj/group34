<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\Product;
use App\Services\AirconLineGrouper;
use App\Services\AirconModelComposer;
use Illuminate\Support\Facades\DB;

/**
 * The derived header count.
 *
 * Every case here is arithmetic over what the document carries against what
 * the model requires — nothing is stored, so a short delivery has to come back
 * as "2 complete, 1 unpaired indoor" rather than as an error.
 *
 * Models are composed through AirconModelComposer rather than by inserting
 * rows, so the fixtures obey the piece-count rule the rest of the system
 * enforces.
 */
class AirconLineGrouperTest extends AirconTestCase
{
    private AirconLineGrouper $grouper;

    private AirconModelComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->grouper  = new AirconLineGrouper();
        $this->composer = new AirconModelComposer();
    }

    /**
     * A two-piece model needing $indoorQty indoor units and one outdoor,
     * plus the two member products.
     *
     * @return array{0:AirconModel,1:Product,2:Product}
     */
    private function splitModel(int $indoorQty = 1): array
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $model = $this->composer->create([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ], [
            ['product_id' => $indoor->product_id, 'quantity' => $indoorQty],
            ['product_id' => $outdoor->product_id, 'quantity' => 1],
        ]);

        return [$model, $indoor, $outdoor];
    }

    /** @return array<string,mixed> */
    private function line(Product $product, int $quantity, ?int $modelId): array
    {
        return [
            'product_id'      => (int) $product->product_id,
            'quantity'        => $quantity,
            'aircon_model_id' => $modelId,
        ];
    }

    // ------------------------------------------------- the worked examples

    public function test_a_balanced_one_to_one_model_reports_every_pair_complete(): void
    {
        [$model, $indoor, $outdoor] = $this->splitModel(1);

        $groups = $this->grouper->group([
            $this->line($indoor, 3, $model->aircon_model_id),
            $this->line($outdoor, 3, $model->aircon_model_id),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame((int) $model->aircon_model_id, $groups[0]['aircon_model_id']);
        $this->assertSame($model->model_name, $groups[0]['model_name']);
        $this->assertSame(3, $groups[0]['complete_count']);
        $this->assertSame([], $groups[0]['unpaired']);
        $this->assertFalse($groups[0]['is_unbalanced']);
        $this->assertCount(2, $groups[0]['lines']);
    }

    public function test_a_short_outdoor_delivery_reports_the_leftover_indoor(): void
    {
        [$model, $indoor, $outdoor] = $this->splitModel(1);

        $groups = $this->grouper->group([
            $this->line($indoor, 3, $model->aircon_model_id),
            $this->line($outdoor, 2, $model->aircon_model_id),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertSame(['indoor' => 1], $groups[0]['unpaired']);
        $this->assertTrue($groups[0]['is_unbalanced']);
    }

    public function test_a_multi_split_counts_three_indoor_units_to_one_outdoor(): void
    {
        [$model, $indoor, $outdoor] = $this->splitModel(3);

        $groups = $this->grouper->group([
            $this->line($indoor, 6, $model->aircon_model_id),
            $this->line($outdoor, 2, $model->aircon_model_id),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertSame([], $groups[0]['unpaired']);
        $this->assertFalse($groups[0]['is_unbalanced']);
    }

    /**
     * 5 indoor against a 3-per-model requirement is one complete multi-split,
     * leaving 2 indoor over. The outdoor side is 2 present against 1 required
     * per model, so the second outdoor is left over too — it has no three
     * indoor units to pair with. Both remainders are reported; hiding the
     * outdoor one would understate the imbalance.
     */
    public function test_an_uneven_multi_split_reports_both_leftover_sides(): void
    {
        [$model, $indoor, $outdoor] = $this->splitModel(3);

        $groups = $this->grouper->group([
            $this->line($indoor, 5, $model->aircon_model_id),
            $this->line($outdoor, 2, $model->aircon_model_id),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(1, $groups[0]['complete_count']);
        $this->assertSame(['indoor' => 2, 'outdoor' => 1], $groups[0]['unpaired']);
        $this->assertTrue($groups[0]['is_unbalanced']);
    }

    public function test_a_missing_role_makes_nothing_complete(): void
    {
        [$model, $indoor] = $this->splitModel(1);

        $groups = $this->grouper->group([
            $this->line($indoor, 3, $model->aircon_model_id),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(0, $groups[0]['complete_count']);
        $this->assertSame(['indoor' => 3], $groups[0]['unpaired']);
        $this->assertTrue($groups[0]['is_unbalanced']);
    }

    // ----------------------------------------------------- shapes and mixes

    public function test_lines_with_no_model_come_back_one_group_each(): void
    {
        $loose  = $this->unitProduct('outdoor');
        $other  = $this->unitProduct('complete');

        $groups = $this->grouper->group([
            $this->line($loose, 2, null),
            $this->line($other, 1, null),
        ]);

        $this->assertCount(2, $groups);

        foreach ($groups as $group) {
            $this->assertNull($group['aircon_model_id']);
            $this->assertNull($group['model_name']);
            $this->assertNull($group['complete_count']);
            $this->assertFalse($group['is_unbalanced']);
            $this->assertSame([], $group['unpaired']);
            $this->assertCount(1, $group['lines']);
        }

        $this->assertSame((int) $loose->product_id, $groups[0]['lines'][0]['product_id']);
        $this->assertSame((int) $other->product_id, $groups[1]['lines'][0]['product_id']);
    }

    public function test_two_models_in_one_document_make_two_groups(): void
    {
        [$modelA, $indoorA, $outdoorA] = $this->splitModel(1);
        [$modelB, $indoorB, $outdoorB] = $this->splitModel(1);

        $groups = $this->grouper->group([
            $this->line($indoorA, 2, $modelA->aircon_model_id),
            $this->line($indoorB, 1, $modelB->aircon_model_id),
            $this->line($outdoorA, 2, $modelA->aircon_model_id),
            $this->line($outdoorB, 1, $modelB->aircon_model_id),
        ]);

        $this->assertCount(2, $groups);

        $this->assertSame((int) $modelA->aircon_model_id, $groups[0]['aircon_model_id']);
        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertCount(2, $groups[0]['lines']);

        $this->assertSame((int) $modelB->aircon_model_id, $groups[1]['aircon_model_id']);
        $this->assertSame(1, $groups[1]['complete_count']);
        $this->assertCount(2, $groups[1]['lines']);
    }

    public function test_a_grouped_model_and_a_loose_line_produce_both_shapes(): void
    {
        [$model, $indoor, $outdoor] = $this->splitModel(1);
        $loose = $this->unitProduct('complete');

        $groups = $this->grouper->group([
            $this->line($indoor, 3, $model->aircon_model_id),
            $this->line($loose, 4, null),
            $this->line($outdoor, 3, $model->aircon_model_id),
        ]);

        $this->assertCount(2, $groups);

        $this->assertSame((int) $model->aircon_model_id, $groups[0]['aircon_model_id']);
        $this->assertSame(3, $groups[0]['complete_count']);
        $this->assertCount(2, $groups[0]['lines']);

        $this->assertNull($groups[1]['aircon_model_id']);
        $this->assertNull($groups[1]['complete_count']);
        $this->assertSame(4, $groups[1]['lines'][0]['quantity']);
    }

    public function test_an_empty_document_groups_to_nothing(): void
    {
        $this->assertSame([], $this->grouper->group([]));
    }

    // ------------------------------------------------------ the query guard

    /**
     * Three models and a dozen lines must cost the same handful of queries as
     * one model and two lines. Task 14 renders this on every purchase order,
     * issuance and receiving document, so a per-line lookup here is a per-line
     * lookup on three screens.
     */
    public function test_grouping_batches_its_lookups_regardless_of_line_count(): void
    {
        $lines = [];

        for ($i = 0; $i < 3; $i++) {
            [$model, $indoor, $outdoor] = $this->splitModel(1);

            $lines[] = $this->line($indoor, 3, $model->aircon_model_id);
            $lines[] = $this->line($outdoor, 2, $model->aircon_model_id);
            $lines[] = $this->line($this->unitProduct('complete'), 1, null);
        }

        // A dozen-odd lines, six of them behind three distinct models.
        $this->assertGreaterThanOrEqual(9, count($lines));

        DB::enableQueryLog();
        $groups = $this->grouper->group($lines);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(6, $groups);

        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'expected the grouper to resolve models and roles in batched queries - '
            . 'one lookup per line is an N+1 on every document screen. Queries run: '
            . implode(' | ', array_column($queries, 'query'))
        );
    }
}
