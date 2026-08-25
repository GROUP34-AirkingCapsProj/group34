<?php

namespace App\Services;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;
use Illuminate\Support\Collection;

/**
 * Turns a document's flat detail lines into aircon groups with a DERIVED
 * header count.
 *
 * A split aircon is two products — an indoor unit and an outdoor unit — with
 * their own cartons, serials and stock counts. A purchase order for three of
 * them is an indoor line of 3 and an outdoor line of 3. The document should
 * render one header ("TCL 2HP Split · 3 complete") with those two lines nested
 * beneath it.
 *
 * The header count is never stored. When a delivery arrives short — outdoor
 * received at 2 against an indoor line of 3 — the group is not invalid; it
 * reports 2 complete and 1 unpaired indoor. A stored parent quantity would
 * have to either misreport that delivery or refuse it, and short deliveries
 * are normal here. The two sides' stock counts legitimately drift.
 *
 * This is one service rather than three controllers because purchase orders,
 * issuances and receiving all render it. Three copies of this arithmetic
 * would drift apart.
 */
class AirconLineGrouper
{
    /**
     * The bucket a member product with no appliance_details.unit_role falls
     * into. It can never match a required role — the requirement is built from
     * the same column — so such a quantity always surfaces as unpaired rather
     * than being silently dropped.
     */
    private const ROLE_UNKNOWN = 'unknown';

    /**
     * @param  array<int,array{product_id:int,quantity:int,aircon_model_id:?int}>  $lines
     * @return array<int,array<string,mixed>>
     */
    public function group(array $lines): array
    {
        $lines = array_values($lines);

        if ($lines === []) {
            return [];
        }

        $modelIds = $this->distinctModelIds($lines);

        /** @var Collection<int,AirconModel> $models */
        $models = $modelIds === []
            ? collect()
            : AirconModel::with('units')->whereIn('aircon_model_id', $modelIds)->get()
                ->keyBy('aircon_model_id');

        // One role lookup covering both sides: the products the document
        // carries, and the products the models are composed of. Queried off
        // appliance_details directly rather than through Product, for the same
        // reason AirconModelComposer::rolesFor() does — a member product may be
        // soft-deleted and its role must still read.
        $roleByProduct = $this->rolesFor($lines, $models);

        $groups   = [];
        $indexFor = [];

        foreach ($lines as $line) {
            $modelId = $line['aircon_model_id'] ?? null;
            $modelId = $modelId === null ? null : (int) $modelId;

            // A replacement outdoor unit ordered on its own is an ordinary
            // product line, not a broken group: it comes back as its own
            // single-line group with every derived field emptied.
            if ($modelId === null) {
                $groups[] = [
                    'aircon_model_id' => null,
                    'model_name'      => null,
                    'complete_count'  => null,
                    'is_unbalanced'   => false,
                    'unpaired'        => [],
                    'lines'           => [$line],
                ];

                continue;
            }

            if (! isset($indexFor[$modelId])) {
                $indexFor[$modelId] = count($groups);

                $groups[] = [
                    'aircon_model_id' => $modelId,
                    'model_name'      => $models->get($modelId)?->model_name,
                    'complete_count'  => 0,
                    'is_unbalanced'   => false,
                    'unpaired'        => [],
                    'lines'           => [],
                ];
            }

            $groups[$indexFor[$modelId]]['lines'][] = $line;
        }

        foreach ($indexFor as $modelId => $index) {
            $required = $this->requirementFor($models->get($modelId), $roleByProduct);
            $present  = $this->presentFor($groups[$index]['lines'], $roleByProduct);

            $complete = $this->completeCount($required, $present);
            $unpaired = $this->unpaired($required, $present, $complete);

            $groups[$index]['complete_count'] = $complete;
            $groups[$index]['unpaired']       = $unpaired;
            $groups[$index]['is_unbalanced']  = $unpaired !== [];
        }

        return $groups;
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return list<int>
     */
    private function distinctModelIds(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            $id = $line['aircon_model_id'] ?? null;

            if ($id !== null) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Roles for every product the document carries and every product the
     * models are composed of, in one query. Never per line — a 20-line
     * document must not cost 20 lookups.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  Collection<int,AirconModel>  $models
     * @return array<int,string|null>
     */
    private function rolesFor(array $lines, Collection $models): array
    {
        $productIds = [];

        foreach ($lines as $line) {
            $productIds[(int) ($line['product_id'] ?? 0)] = true;
        }

        foreach ($models as $model) {
            foreach ($model->units as $unit) {
                $productIds[(int) $unit->product_id] = true;
            }
        }

        $productIds = array_keys($productIds);

        if ($productIds === []) {
            return [];
        }

        return ApplianceDetail::whereIn('product_id', $productIds)
            ->pluck('unit_role', 'product_id')
            ->all();
    }

    /**
     * How many of each role one complete aircon needs, summed per role across
     * the model's unit lines. A 3:1 multi-split may be three indoor lines of 1
     * or one indoor line of 3 — both come out as 3 indoor.
     *
     * A model id with no row behind it yields an empty requirement, which
     * makes complete_count 0 and reports every present quantity as unpaired.
     * That is a dangling reference showing itself rather than hiding.
     *
     * @param  array<int,string|null>  $roleByProduct
     * @return array<string,int>
     */
    private function requirementFor(?AirconModel $model, array $roleByProduct): array
    {
        if ($model === null) {
            return [];
        }

        $required = [];

        foreach ($model->units as $unit) {
            $role = $roleByProduct[(int) $unit->product_id] ?? self::ROLE_UNKNOWN;
            $qty  = (int) $unit->quantity;

            if ($qty <= 0) {
                continue;
            }

            $required[$role] = ($required[$role] ?? 0) + $qty;
        }

        return $required;
    }

    /**
     * What the document actually carries per role.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,string|null>  $roleByProduct
     * @return array<string,int>
     */
    private function presentFor(array $lines, array $roleByProduct): array
    {
        $present = [];

        foreach ($lines as $line) {
            $role = $roleByProduct[(int) ($line['product_id'] ?? 0)] ?? self::ROLE_UNKNOWN;

            $present[$role] = ($present[$role] ?? 0) + (int) ($line['quantity'] ?? 0);
        }

        return $present;
    }

    /**
     * The most whole aircons the present lines can make.
     *
     * A required role the document does not carry contributes 0 to the min,
     * which is the honest answer: three indoor units and no outdoor make zero
     * complete aircons and three unpaired indoor.
     *
     * @param  array<string,int>  $required
     * @param  array<string,int>  $present
     */
    private function completeCount(array $required, array $present): int
    {
        if ($required === []) {
            return 0;
        }

        $counts = [];

        foreach ($required as $role => $need) {
            $have = max(0, $present[$role] ?? 0);

            $counts[] = intdiv($have, $need);
        }

        return min($counts);
    }

    /**
     * What is left over once complete_count whole aircons are taken out.
     *
     * Computed over every role the document carries, not only the required
     * ones. On a 3-indoor-to-1-outdoor model carrying 5 indoor and 2 outdoor,
     * complete_count is 1 (5/3 floors to 1, and that is the binding term); the
     * leftovers are 2 indoor AND 1 outdoor, because the second outdoor has no
     * three indoor units to pair with. Reporting only the indoor remainder
     * would hide half the imbalance from the receiving clerk.
     *
     * Only positive remainders are kept.
     *
     * @param  array<string,int>  $required
     * @param  array<string,int>  $present
     * @return array<string,int>
     */
    private function unpaired(array $required, array $present, int $complete): array
    {
        $unpaired = [];

        // Required roles first, in the model's own order, so the leftovers
        // read in the same order as the model's composition.
        $roles = array_keys($required + $present);

        foreach ($roles as $role) {
            $remainder = ($present[$role] ?? 0) - ($complete * ($required[$role] ?? 0));

            if ($remainder > 0) {
                $unpaired[$role] = $remainder;
            }
        }

        return $unpaired;
    }
}
