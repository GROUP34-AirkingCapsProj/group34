# Appliance model as free-text `model_code` — design

**Date:** 2026-06-25
**Branch:** backupV3
**Status:** Approved scope, pending spec review

## Problem

An appliance's "model" is currently stored as `appliance_details.model_id`, a
foreign key into a `model_lookup` lookup table. When a user types a model code
while adding/editing an appliance, `ProductController` silently calls
`ModelLookup::firstOrCreate(...)` to materialize a `model_lookup` row and stores
its id. This indirection is unwanted: the model is just a label the user types
per product. There is no need for a shared lookup table, ids, dropdowns, or CRUD.

Supporting facts (verified against live `airkingV5`):
- `model_lookup`: **0 rows** (0 active, 0 soft-deleted).
- `appliance_details`: 3 rows, **all `model_id` NULL** → no data to migrate.
- `products.model_id` and `profit_loss.model_id` columns **do not exist** in the
  live DB (code/schema drift); only `appliance_details.model_id` is real.
- Only inbound FK to `model_lookup` is `appliance_details_model_id_foreign`.

## Goal

Store the appliance model as a plain typed string on `appliance_details`, and
remove `model_lookup` and all its machinery entirely.

## Design

### Schema (reversible migration, NOT run by us)

New migration, timestamped **after** `2026_06_25_100000_drop_subcategory_lookup`
(that one already removes `model_lookup.subcategory_id`). Suggested:
`2026_06_25_110000_replace_appliance_model_id_with_model_code`.

`up()`:
1. `appliance_details`: drop FK `appliance_details_model_id_foreign`, drop column
   `model_id`, add `model_code VARCHAR(100) NULL` (positioned after `brand_id`).
2. `Schema::dropIfExists('model_lookup')`.

`down()`:
1. Recreate `model_lookup` in its **post-subcategory-migration shape** (no
   `subcategory_id`): `model_id` PK auto-increment, `brand_id` nullable,
   `model_code varchar(100) NOT NULL`, `variant varchar(100) NULL`,
   `capacity varchar(50) NULL`, `status_id` nullable, nullable timestamps,
   `deleted_at` nullable; unique `model_lookup_brand_model_unique (brand_id, model_code)`;
   FKs `model_lookup_brand_id_foreign` → `brand_lookup` (SET NULL) and
   `model_lookup_status_id_foreign` → `status_lookup` (SET NULL).
2. `appliance_details`: drop `model_code`, add `model_id` bigint unsigned nullable,
   re-add FK `appliance_details_model_id_foreign` → `model_lookup` (SET NULL).

Note: `model_code` text is not preserved across a rollback (structural reverse
only). Acceptable — rollback restores schema, not lookup ids that never existed.

Migration ordering for full rollback: roll back this migration first (recreates
`model_lookup` w/o `subcategory_id`, restores `appliance_details.model_id`), then
the subcategory migration (recreates `subcategory_lookup` + re-adds
`model_lookup.subcategory_id`).

### Backend code

- `ApplianceDetail.php`: add `model_code` to `$fillable`; remove `model_id` from
  fillable; delete `model()` and `modelLookup()` relations.
- `ProductController` `store()`/`update()`: delete the `ModelLookup::firstOrCreate`
  blocks; write `model_code` straight into `appliance_details`. Replace the
  surfaced fields so `model_code`/`model_name` come from `appliance_details.model_code`
  directly; drop `applianceDetail.modelLookup` from all eager-loads and the
  `setRelation('modelLookup', ...)` line.
- Remove `applianceDetail.modelLookup` (and any `applianceDetail.model`) from
  eager-load chains in: `InventoryController`, `InventoryScanTransactionController`,
  `IssuanceScanController`, `PurchaseOrderController`, `PurchaseRequestController`,
  `WarrantyController`, `BarcodeScanController`. Keep `applianceDetail.brand`.
- `ProfitLoss.php`: remove `model_id` from fillable + delete `model()` relation.
  `ProfitLossController`: remove `'model'` eager-loads and `model_id`
  validation/store.
- Delete `ModelLookupController`; remove `/models*` routes + import in `api.php`.
- `ArchiveController`: remove the `'models' => ModelLookup::class` module.
- `BrandLookup.php`: remove `models()` relation.
- Delete `ModelLookup` model.

### Frontend

- `ItemManagement.jsx`: already a free-text `model_code` input — repoint the edit
  hydration (`item.model_name` → `item.model_code`) and any display to `model_code`.
- `BarcodeScan.jsx`: remove `modelsAPI.getAll` on mount; convert the MODEL CODE
  filter `<select>` into a text input that filters on `model_code`.
- `ProfitLossManagement.jsx`: remove `modelsAPI`, the model `<select>`, and the
  `model_id` form field + `modelCode()` lookups.
- `ISTDetailPage.jsx`, `ISTReceiptPage.jsx`, `resources/views/ist/receipt.blade.php`:
  read `product.model_code` instead of `product.model?.model_name`.
- Remove `modelsAPI` from `services/api.js`.

### Out of scope / leave alone

- One-off scripts (`scripts/import_legit_products_from_csv.php`,
  `scripts/extract_legit_products_from_images.js`) and historical migrations are
  not edited.
- `products.model_id` / `profit_loss.model_id` columns already don't exist —
  nothing to drop there.

## Execution plan (phased, each reviewable)

1. **Migration** (reversible, unrun) — schema swap + drop table.
2. **Backend core** — `ApplianceDetail`, `ProductController` write/read path.
3. **Backend cascade** — eager-load removals in the 7 other controllers,
   `ProfitLoss`, `ArchiveController`, `BrandLookup`, routes, delete controller +
   model.
4. **Frontend** — ItemManagement, BarcodeScan, ProfitLossManagement, IST pages,
   blade view, `api.js`.

Migration is not run by us; user backs up and runs it. Verify each backend phase
with `php -l` and a grep for dangling `ModelLookup`/`modelLookup` references.

## Verification

- `php -l` on every changed PHP file.
- Grep: zero `ModelLookup`/`modelLookup`/`model_lookup`/`modelsAPI` references
  remain (outside the migration + ignored scripts).
- Migration up/down cycle on a throwaway sandbox DB, diffing `SHOW CREATE TABLE`
  for `appliance_details` and `model_lookup` (same method used for subcategory).
