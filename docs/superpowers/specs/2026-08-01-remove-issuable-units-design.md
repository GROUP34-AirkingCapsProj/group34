# Remove Issuable Units — Design

**Date:** 2026-08-01
**Branch:** backupV3
**Status:** Approved for planning

## Why

Issuable units let an admin define named issue quantities per product ("1 Roll" = 1 piece, "1 Box (10 Rolls)" = 10 pieces). The intent was that warehouse staff would pick a unit from a dropdown at issue-out instead of typing a piece count.

**The feature is redundant, and was never wired to the UI.** Three findings, each verified against the code:

1. **Issue-out never reads it.** `IssuanceScanController:421` takes a plain quantity from the request and deducts it:
   ```php
   $requestedQty     = (float) ($validated['quantity'] ?? 1);
   $quantityInPieces = (int) ceil(round($requestedQty, 4));
   ```
   `conversion_factor` is not consulted on the deduction path.

2. **Nothing renders the output.** `BarcodeScanController:119` and `IssuanceScanController:557` return `quick_actions` and `has_issuable_units`. A grep of `frontend/src` finds no consumer of either. The only `quick_action` hits are `show_as_quick_action` — the checkbox that *configures* the flag in Item Master.

3. **The need it addressed is already met elsewhere.** `OrderBuilder.jsx` (order creation) has adjacent `packages` and `pieces` number inputs, so staff already enter "N packages + M pieces" rather than raw piece counts. At picking time `PickScanner.jsx:136` takes the quantity from the FIFO pick-list suggestion — the picker makes no quantity choice at all. There is nowhere left for an issuable-unit dropdown to live.

## What is being removed

Both tables and everything that references them.

**Frontend**
- `ItemManagement.jsx` — the Issuable Units section (table, add-row button, preset picker), the `issuableUnits` / `issuableUnitPresets` state, `handleAddIssuableUnit`, `handleAddIssuableUnitFromPreset`, `updateIssuableUnit`, `removeIssuableUnit`, `saveIssuableUnits`, and the load-on-edit effect.
- `lookupConfigs.js` — the `presets` entry and its Installation Materials tab membership. The sub-tab then disappears on its own; `LookupManagement.jsx` renders from config and needs no edit.
- `api.js` — `issuableUnitPresetsAPI`, and `productsAPI`'s four issuable-unit methods.

**API surface**
- `routes/api.php` — 8 preset routes plus 4 product issuable-unit routes.
- `IssuableUnitPresetController` — deleted.
- `ProductController` — `issuableUnits`, `storeIssuableUnit`, `updateIssuableUnit`, `destroyIssuableUnit`.
- `BatchController` — the `issuable_unit_presets` include.
- `BarcodeScanController` and `IssuanceScanController` — `quick_actions` and `has_issuable_units` removed from both payloads.

**Model layer**
- `Product::buildConsumableQuickActions()` and the `issuableUnits()` relation.
- Models `ProductIssuableUnit` and `IssuableUnitPreset`.
- `LookupBackfill::backfillPresets()` and its call from `run()`.

**Schema**
- Migration dropping `product_issuable_units` and `issuable_unit_preset`.
- `airkingv5.sql` updated to match.

## What stays, and why

- **`installation_material_details.pieces_per_package`** — load-bearing for receiving (`1 scan = 1 package = N pieces`), for per-piece costing (`unit_price / pieces_per_package`, used by the category and brand value aggregates), and for `packages_on_hand`.
- **`unit_lookup` and its management page** — five other lookups depend on it.
- **`lookup_management` permission** — unchanged; it covers the remaining five lookups.
- **`ItemSerial::issuableAt()`** and the two Transfer tests using it. That scope means "this serial is available to issue" — an unrelated sense of the word.
- **`SetSchemaTest`'s assertion that `product_set_lines` has no `issuable_unit_id`.** It asserts absence, so it stays true and stays useful — evidence a previous cleanup already retreated from this feature once.

## Decisions

Settled during brainstorming; recorded so the reasoning survives.

1. **Full removal, tables dropped, data discarded.** The 167 rows go — including the 122 that encode sub-package issuing. A `mysqldump` of both tables is taken before the drop as insurance, but is not committed. Options to keep the tables dormant, or to export the rows into a seeder first, were considered and declined.
2. **`quick_actions` and `has_issuable_units` are deleted outright**, not left as empty stubs and not preserved in a fallback-only form. An unread field is dead weight, and a hollow one invites someone to wire it back up. This is an internal API with a single React client in the same repo, so there is no external consumer to break.
3. **Unit-deletion protection is re-anchored, not restored.** Dropping both tables removes the last two `RESTRICT` foreign keys to `unit_lookup`, leaving only `installation_material_details → SET NULL`. The app-level guard in `UnitLookupController::forceDestroy` (which refuses when `usageCountFor` is above zero) remains the protection. Rather than lose the coverage, `RetrofitCompatTest`'s force-delete case is re-pointed at `installation_material_details`. Changing that FK to `RESTRICT` was considered and declined as scope creep into a table this removal does not otherwise touch.
4. **Staged by layer, consumers before providers.** Frontend, then API surface, then model layer, then schema. At no point is anything calling something that no longer exists, and a red suite identifies its own stage. A single atomic commit and a schema-first order were both declined — the latter because dropping tables first breaks four things at once and turns a removal into a debugging session.

## Stages

Each ends green before the next begins.

**Stage 1 — Frontend.** Backend untouched; the endpoints simply stop being called.
Verify: eslint (`ItemManagement.jsx` stays at exactly its two pre-existing warnings — `react-hooks/exhaustive-deps` on `fetchItems` and unused `passesCatalogCompleteness`; `lookupConfigs.js` at zero), then `npm run build`. Confirm by eye that Installation Materials now shows one sub-tab and Item Master has no Issuable Units section.

**Stage 2 — API surface.** Routes, controller, `ProductController` methods, batch include, payload fields. Test changes land here.
Verify: `php artisan test --filter=Lookups`, and `php artisan route:list --path=issuable` returns nothing.

**Stage 3 — Model layer.** `buildConsumableQuickActions()`, the relation, both models, `backfillPresets()`.
Verify: full `php artisan test` — `Product` has many consumers.

**Stage 4 — Schema.** Drop migration applied to `airkingV5` and `airkingV5_test`, plus the dump edit.

**Before running the migration**, take the insurance dump — this is the irreversible stage:

```bash
mysqldump -u root airkingV5 product_issuable_units issuable_unit_preset > issuable-units-backup.sql
```

Keep it outside the repo; it is not committed. It costs thirty seconds and is the only thing standing between a reversal and re-entering 167 rows by hand.

Verify: both tables absent from both databases, and `grep -c "product_issuable_units\|issuable_unit_preset" airkingv5.sql` returns zero.

## Schema handling

**The migration** — `2026_08_01_000001_drop_issuable_unit_tables.php`, guarded with `Schema::hasTable` so it is safe to re-run. Drop order is irrelevant: neither table references the other, both reference `products` and `unit_lookup`, and `DROP TABLE` removes their foreign keys automatically.

**`down()` recreates the schema but not the data**, and says so in a comment. A rollback should not leave the migration table claiming a state the database is not in, but the rows are gone the moment `up()` runs.

**The dump edit.** `product_issuable_units` appears in `airkingv5.sql` in five separate blocks, all of which must go:

| Approx. line | Block |
|---|---|
| 2625 | `-- Table structure` + `CREATE TABLE` |
| 2642 | `-- Dumping data` + `INSERT INTO` (167 rows) |
| 4675 | `-- Indexes` + `ADD PRIMARY KEY` / `ADD KEY` |
| 5227 | `-- AUTO_INCREMENT` + `ALTER TABLE … MODIFY` |
| 5666 | `-- Constraints` + two `ADD CONSTRAINT` foreign keys |

`issuable_unit_preset` appears **nowhere** in the dump — it was created by a migration during this session, so the dump predates it. Nothing to remove for that table.

This step is easy to skip and silently undoes itself: a fresh install re-imports the dump, so a `CREATE TABLE` left behind resurrects the table on every new machine. The same class of bug was hit earlier in this project, where a column-nullability fix lived only in a migration while the dump still declared the old definition.

**Applied to both databases.** There is no separate test connection — `phpunit.xml` overrides `DB_DATABASE` on the default one, so target the test database from the **Bash** tool as `DB_DATABASE=airkingV5_test php artisan migrate --path=…`. The env-prefix form does not work in PowerShell.

## Testing

**Deleted:** `IssuableUnitPresetTest` (12 tests) — it covers only the endpoint being removed.

**Amended:**
- `LookupRouteAccessTest` — the two "can read all six lookups" cases drop the presets endpoint and become five. Every write-denial assertion stays, especially `test_warehouse_personnel_cannot_write_a_capacity`, which is the only one proving the permission gate rather than the blanket `auditor.readonly` middleware.
- `BatchIncludesTest` — remove `issuable_unit_presets` from the include assertions; delete the nested-`unit` case.
- `BackfillTest` — remove the preset backfill case.
- `RetrofitCompatTest` — replace the force-delete case with one anchored to `installation_material_details`: create a unit, attach an installation material, archive the unit, assert force-delete returns 422 and the row survives.

**Untouched:** `SetSchemaTest`, `SerialIssuableScopeTest`, `SerialIntegrityFlowTest`.

**The real acceptance criteria.** The claim that issue-out and inventory are unaffected is not proven by the tests being deleted — it is proven by the ones that must stay green: `IssuanceBatchFifoTest` (FIFO deduction), `IssuanceRemoveItemBatchTest` and `CancelIssuanceTest` (restore on cancel), `StockHistoryMovementSummaryTest` (the ledger). None of them reference issuable units, which independently corroborates the redundancy finding. Run them at **every** stage, not only at the end.

**No new tests beyond the re-anchored guard.** Asserting a route 404s is weak coverage that `route:list` provides for free, and there is no React test harness, so stage 1 is honestly verified by build plus inspection rather than by a test that pretends otherwise.

**Expected end state:** failures stay at exactly **9** (`WarrantyClaimTest`), plus the flaky `SerialRowMovementColumnsTest` roughly one run in three.

Passing count drops by **about 14**: 12 from `IssuableUnitPresetTest`, one from `BatchIncludesTest`'s nested-`unit` case, one from `BackfillTest`'s preset case. `RetrofitCompatTest` and `LookupRouteAccessTest` are net-neutral — assertions change but no test is added or removed. Baseline before removal is 706 passing, so expect roughly **692**. Any failure outside the two known classes is the removal breaking something.
