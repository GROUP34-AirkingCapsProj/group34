# Fill Empty Product Details — Design

**Date:** 2026-07-07
**Branch:** backupV3
**Deliverable:** `back-end/database/seeders/DemoProductDataSeeder.php`

## Problem

The live `airkingV5` database has 336 products (208 appliances, 128 consumables)
whose descriptive and commercial fields are entirely empty, making the app look
half-populated in demos and testing. Populated fields (category, brand, variant,
capacity, status) are left untouched.

### Empty fields (all 336 rows unless noted)

| Table | Field | State |
|---|---|---|
| products | barcode | empty |
| products | description | empty |
| products | unit_price | 0.00 |
| products | cost_price | 0.00 |
| products | recommended_stocks | 0 |
| products | safety_stock | NULL |
| products | max_stock_level | NULL |
| appliance_details | model_code | empty (208) |
| appliance_details | color | empty (208) |
| consumable_details | pieces_per_package | NULL (89) |
| consumable_details | package_unit | empty (88) |
| consumable_details | warranty_value | 0 (128) |

**Legitimately left alone:** `appliance_details.capacity_rating` NULLs (69 rows —
TVs/fridges/washers have no HP rating); consumable pieces/package for "(Per Foot)"
and "(Per Piece)" single-sold items.

## Decisions (from brainstorming)

- **Data source:** realistic *demo/test* data (synthetic, not real business values).
- **Delivery:** repeatable Laravel seeder, idempotent (only writes empty/zero fields).
- **Realism:** capacity/category-tiered pricing and stock levels.

## Fill rules

### products.unit_price — tiered base
- **Aircon** (category Air Conditioning): base by HP —
  0.6HP≈18k, 0.8HP≈20k, 1HP≈28k, 1.5HP≈38k, 2HP≈52k, 2.5HP≈62k, 3HP≈75k,
  4HP≈95k, 5HP≈120k, 6HP≈145k, 7HP≈170k (PHP).
- **Television:** by screen size parsed from name — 32"≈9k, 40"≈15k, 43"≈18k,
  50"≈28k, 55"≈35k, 65"≈55k; fallback 15k.
- **Refrigerator:** 15k–45k. **Washing Machine:** 12k–35k. **Small Appliances:** 1k–5k.
- **Consumables:** 50–2,000 by keyword (copper tube per-roll pricier, PE foam cheap, etc.).
- A small deterministic per-`product_id` jitter (±~6%) prevents identical prices.

### products.cost_price
`round(unit_price × factor)` where factor ∈ [0.68, 0.78], deterministic per product_id.
Always below retail.

### stock levels
- `recommended_stocks`: appliances 5–20, consumables 30–200 (deterministic by product_id).
- `safety_stock`: `round(recommended × 0.35)`, min 1.
- `max_stock_level`: `round(recommended × 2.5)`.

### products.barcode
Valid **EAN-13** with check digit, unique, deterministic:
`480` prefix + zero-padded product_id → 12 digits → append computed check digit.
Compatible with the barcode-scan module.

### products.description
Templated from real fields, e.g.
`"CHiQ Morandi Inverter 1HP split-type air conditioner. 12-month warranty."`
Built from brand + variant + capacity + category noun + warranty.

### appliance_details.model_code
Use `product_code` when it is a real manufacturer code (not `PROD-nnn`);
for TVs/others parse the trailing model token from the product name.

### appliance_details.color
Default White for aircon/refrigerator/washer; Black for TV/soundbar.

### consumable_details
- `pieces_per_package` + `package_unit`: default pack size for package-sold items;
  keep NULL for "(Per Foot)"/"(Per Piece)" singles.
- `warranty_value`: 3 (months) for AC Parts that plausibly carry warranty; else left 0.

## Mechanics

- Written via `DB::table()->update()` inside a `DB::transaction()` — fast, idempotent,
  and avoids the `Auditable` trait writing 300+ audit_trail rows.
- Every write guarded by a `WHERE` that matches only NULL/empty/0 — re-running is safe
  and never clobbers real values entered later.
- **Standalone only** — deliberately NOT registered in `DatabaseSeeder` (which is a
  fresh-install seeder that recreates roles/users and would conflict with the live DB).
  Run via `php artisan db:seed --class=DemoProductDataSeeder --force`.
- Verification: before/after null-count query printed to console.

## Out of scope
- Real pricing/stock figures (these are demo values).
- Editing populated fields.
- Frontend changes.
