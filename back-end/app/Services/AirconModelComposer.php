<?php

namespace App\Services;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Composes aircon models and holds the one rule that makes a composition legal:
 * a two-piece model needs both sides, a one-piece model needs exactly one
 * complete unit.
 *
 * This lives in a service rather than in the controller because the model
 * builder is not the only way a model gets composed — the conversion tool that
 * turns loose appliance products into models composes them too. A rule enforced
 * at one of two entry points is not a rule, so both go through here.
 *
 * Roles are never taken from the caller's payload. They are read from
 * appliance_details.unit_role via the product ids, which is the single source of
 * truth (see the comment beside the unique() call in the create-tables
 * migration). A payload claiming a line is "outdoor" cannot make it so.
 */
class AirconModelComposer
{
    /** @var list<string> */
    private const ROLES_TWO_PIECE = ['indoor', 'outdoor'];

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array{product_id:int|string,quantity?:int|string|null}>  $lines
     */
    public function create(array $attributes, array $lines): AirconModel
    {
        // Before the transaction, not inside it: a rejected composition must
        // leave nothing behind, and validating first means there is nothing to
        // roll back in the first place.
        $this->assertPieceCount((bool) ($attributes['is_two_piece'] ?? true), $lines);

        return DB::transaction(function () use ($attributes, $lines) {
            // array_merge, not +, so a payload carrying its own created_by
            // cannot spoof the authorship.
            $model = AirconModel::create(array_merge($attributes, [
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]));

            $this->syncLines($model, $lines);

            return $model->fresh(['units.product', 'brand', 'acType']);
        });
    }

    /**
     * $attributes is partial — only the keys present are changed. $lines === null
     * leaves the existing lines untouched.
     *
     * @param  array<string,mixed>  $attributes
     * @param  list<array{product_id:int|string,quantity?:int|string|null}>|null  $lines
     */
    public function update(AirconModel $model, array $attributes, ?array $lines = null): AirconModel
    {
        return DB::transaction(function () use ($model, $attributes, $lines) {
            // Row lock BEFORE the read that feeds the check, and the check
            // inside the transaction — otherwise two concurrent updates
            // interleave and defeat the one invariant this service exists to
            // hold. Reading the lines, validating, then opening a transaction
            // leaves this window:
            //
            //   A: update(is_two_piece: true, [indoor, outdoor])  - validates OK
            //   B: update(is_two_piece: false)                    - reads the
            //      PRE-A lines (one complete unit), validates OK
            //   A commits, then B commits -> is_two_piece = false against an
            //   indoor+outdoor pair, and nothing ever raised.
            //
            // The lock is on the parent row only. That is enough: every writer
            // reaches the unit lines through this method, so taking the parent
            // lock first serialises them, and the unlocked read of
            // aircon_model_units below happens under that mutual exclusion.
            //
            // findOrFail, not find: a model deleted out from under us must not
            // be silently resurrected by a write. Note newQuery() carries the
            // SoftDeletes scope, so an archived model raises here too.
            $locked = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());

            // The check runs against the combination that will be in force AFTER
            // the update, not the one that arrived. Either half can change on its
            // own: flipping is_two_piece to false while leaving two lines alone
            // sends no lines at all, and validating the incoming payload would wave
            // that through into an illegal state. So both halves fall back to what
            // is stored — read here from the locked row, not from the caller's
            // possibly stale instance.
            $effectiveTwoPiece = array_key_exists('is_two_piece', $attributes)
                ? (bool) $attributes['is_two_piece']
                : (bool) $locked->is_two_piece;

            $effectiveLines = $lines ?? $this->currentLines($locked);

            $this->assertPieceCount($effectiveTwoPiece, $effectiveLines);

            // Authorship is not a caller-supplied attribute.
            $locked->fill(Arr::except($attributes, ['created_by', 'updated_by']));
            $locked->updated_by = Auth::id();
            $locked->save();

            if ($lines !== null) {
                $this->syncLines($locked, $lines);

                // A lines-only edit by the model's own last editor changes no
                // column on aircon_models, so Eloquent short-circuits save()
                // and updated_at keeps reporting the previous edit. The lines
                // did change; "last modified" must say so.
                if (! $locked->wasChanged()) {
                    $locked->touch();
                }
            }

            return $locked->fresh(['units.product', 'brand', 'acType']);
        });
    }

    /**
     * The stored lines, in the shape assertPieceCount() expects.
     *
     * @return list<array{product_id:int,quantity:int}>
     */
    private function currentLines(AirconModel $model): array
    {
        return $model->units()->get()
            ->map(fn ($unit) => [
                'product_id' => (int) $unit->product_id,
                'quantity'   => (int) $unit->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * Replaces the lines wholesale, in the order given, numbering sort_order
     * from the array index.
     *
     * Wholesale rather than a diff because the lines carry no identity of their
     * own worth preserving — a line is a product plus a quantity plus a
     * position, all three of which the caller has just restated.
     *
     * @param  list<array{product_id:int|string,quantity?:int|string|null}>  $lines
     */
    private function syncLines(AirconModel $model, array $lines): void
    {
        $model->units()->delete();

        foreach (array_values($lines) as $index => $line) {
            $model->units()->create([
                'product_id' => $line['product_id'],
                // A zero or negative quantity is a line that contributes
                // nothing but still occupies the model's one slot per product.
                'quantity'   => max(1, (int) ($line['quantity'] ?? 1)),
                'sort_order' => $index,
            ]);
        }

        $model->unsetRelation('units');
    }

    /**
     * The piece-count rule.
     *
     * Two-piece: at least one indoor line and at least one outdoor line. More of
     * either is fine — a 3:1 multi-split is three indoor lines, or one indoor
     * line of quantity 3, against one outdoor.
     *
     * One-piece: exactly one line, and its product must be a complete unit. A
     * window type has nothing to pair with, so a second line — or an indoor line
     * standing in for the whole thing — is a composition error, not a variation.
     *
     * @param  list<array{product_id:int|string,quantity?:int|string|null}>  $lines
     *
     * @throws ValidationException
     */
    private function assertPieceCount(bool $isTwoPiece, array $lines): void
    {
        // Before the role check, because rolesFor() dedupes the ids: without
        // this, [indoor, outdoor, indoor] collapses to ['indoor','outdoor'],
        // passes the rule, and then dies on the
        // unique(aircon_model_id, product_id) index in syncLines() as a 500.
        // "One line per product per model" — wanting a product twice means
        // raising its quantity — is a composition constraint, so it belongs
        // here with the rest of them rather than in one caller's validator.
        $seen = [];
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);

            if (isset($seen[$productId])) {
                $this->reject(sprintf(
                    'Product %d is listed more than once. Raise its quantity instead of adding a second line.',
                    $productId
                ));
            }

            $seen[$productId] = true;
        }

        if ($lines === []) {
            $this->reject($isTwoPiece
                ? 'A two-piece model needs at least one indoor unit and one outdoor unit; no unit lines were given.'
                : 'A one-piece model needs exactly one complete unit; no unit lines were given.');
        }

        $roles = $this->rolesFor($lines);

        if ($isTwoPiece) {
            $missing = array_values(array_filter(
                self::ROLES_TWO_PIECE,
                fn (string $role) => ! in_array($role, $roles, true)
            ));

            if ($missing !== []) {
                $this->reject(sprintf(
                    'A two-piece model needs at least one indoor unit and one outdoor unit. Missing: %s.',
                    implode(' and ', $missing)
                ));
            }

            return;
        }

        if (count($lines) !== 1) {
            $this->reject(sprintf(
                'A one-piece model needs exactly one complete unit line; %d were given.',
                count($lines)
            ));
        }

        if ($roles !== ['complete']) {
            $this->reject(sprintf(
                'A one-piece model\'s unit must be a complete unit; got %s.',
                $roles === [] || $roles === [null] ? 'a product with no unit role' : implode(', ', $roles)
            ));
        }
    }

    /**
     * The unit roles behind these lines, read from appliance_details.
     *
     * Deliberately queried off the table rather than through the Product model:
     * a member product may be soft-deleted (AirconModelUnit::product() uses
     * withTrashed() for exactly that reason) and its role must still read.
     *
     * @param  list<array{product_id:int|string,quantity?:int|string|null}>  $lines
     * @return list<string|null>  Distinct roles present, one entry per distinct role.
     */
    private function rolesFor(array $lines): array
    {
        $productIds = array_values(array_unique(array_map(
            fn (array $line) => (int) ($line['product_id'] ?? 0),
            $lines
        )));

        $byProduct = ApplianceDetail::whereIn('product_id', $productIds)
            ->pluck('unit_role', 'product_id');

        // A product with no appliance_details row — an installation material slipped into a
        // model, say — maps to null and so satisfies no branch of the rule.
        return array_values(array_unique(array_map(
            fn (int $id) => $byProduct->get($id),
            $productIds
        ), SORT_REGULAR));
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['lines' => $message]);
    }
}
