# Aircon Product Details: AC Type and Indoor/Outdoor Pairing

**Date:** 2026-08-25
**Status:** Approved design, not yet planned

## Problem

The product catalogue cannot express two facts about air conditioners.

**There is no AC type.** A split-type, a window-type, a floor-standing, and a
cassette aircon are all just rows in the `Air Conditioning` category. The kind
is recorded only in prose, inside `products.description` ("...split-type air
conditioner. 3-year warranty."), where nothing can filter or group on it.

**There is no indoor/outdoor unit.** A split aircon is installed as an indoor
unit and an outdoor unit. They are ordered together, delivered together, and
installed together, but each is a separate carton with its own serial, its own
price, and its own stock count that can drift from its partner. The catalogue
models each split aircon as a single product row with a single price, which
matches neither how it is received nor how it is stocked.

## Decisions taken

These were settled during brainstorming and constrain everything below.

1. **Both one-piece and two-piece aircons exist.** Window and portable units
   arrive as one carton; split, floor-standing, and cassette units arrive as
   two. The model must carry both.
2. **Indoor and outdoor stock counts drift.** Short deliveries, damage, and
   single-side warranty replacements all produce imbalance. It is a real state
   that must be visible, not an error state to be forbidden.
3. **A single side can be bought and sold on its own** for replacements. Each
   side therefore needs its own price, model code, supplier identity, and
   serials — which makes each side a first-class `products` row.
4. **Documents show one aircon line broken into indoor and outdoor sub-lines**,
   each with its own quantity and serials, so staff can see and correct each
   side.
5. **The pairing is an arbitrary grouping with quantities**, not a fixed 1:1.
   A standard split is 1 indoor + 1 outdoor; a multi-split may be 3 indoor + 1
   outdoor.
6. **AC type suggests the piece count but does not fix it.** Split defaults to
   two-piece, window to one-piece, and the person registering the product may
   override.
7. **Scope is aircon-only.** No general appliance-subtype mechanism and no
   per-category custom attribute system. Televisions, refrigerators, washing
   machines, and water dispensers keep the fields they have.

## Approach

An **aircon model** is a new first-class entity that groups product rows. The
indoor unit and the outdoor unit each remain an ordinary `products` row with
its own price, stock, and serials; the model names the combination and is what
purchase orders and issuances point at.

Two alternatives were rejected:

- **Tagging and grouping products only** — adding `ac_type_id`, `unit_role`,
  and a shared `pair_group_id` to `appliance_details` with no parent entity. It
  is roughly half the work, but there is nowhere to put a model name or the
  piece-count override, multi-split quantities have no home, and every screen
  showing "the aircon" must reassemble it from the group.
- **Reusing `product_sets`** — adding a discriminator and treating aircon
  models as a kind of set. `IssuanceSet` already renders sets with sub-lines,
  so the display would come nearly free. Rejected because the
  `2026_07_27_000001_redesign_product_sets_multibrand` migration deliberately
  removed `set_code`, `set_name`, and the single `brand_id` to make sets
  brand-agnostic BOQ templates matched on capacity and variant. A job BOQ and a
  manufacturer's product identity are different things; merging them into one
  table and one management screen undoes that migration's intent.

## Data model

### `ac_type_lookup` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `ac_type_id` | bigint unsigned PK | |
| `ac_type_name` | varchar(60), unique | Split Type, Window Type, Floor Standing, Cassette, Ducted, Portable |
| `is_two_piece` | tinyint(1), default 1 | Default piece count for this type |
| `created_at` / `updated_at` / `deleted_at` | timestamps | Soft deletes, matching the other lookups |

Managed through the same `HandlesLookupCrud` concern as brands and variants.

Unlike `capacity_rating` and `variant`, which are stored on `appliance_details`
as loose strings and need `LookupRenameService` to cascade a rename across
`appliance_details` and `product_sets`, this is a real foreign key. Renaming a
type is a single-row update and cannot drift. `AcTypeLookupController`
therefore does not implement `cascadeTargets()` or `cascadeValueChange()`, and
`usageCountFor()` counts `appliance_details.ac_type_id` rows directly.

### `appliance_details` (two new columns)

| Column | Type | Notes |
| --- | --- | --- |
| `ac_type_id` | bigint unsigned, nullable, FK → `ac_type_lookup`, null on delete | |
| `unit_role` | varchar(20), nullable, commented `indoor \| outdoor \| complete` | Validated in the controller, not by the schema — see below |

Both nullable: non-aircon appliances do not use them.

`unit_role` is a plain string, not a DB enum. This codebase keeps constrained
vocabularies as string codes and validates them in the controller — the closest
parallel, `products.product_type`, is a varchar checked with
`in:appliance,installation_material`. Only one `->enum(` call exists in the
entire migration history. The convention was paid for:
`2026_08_13_000002_widen_incident_item_status.php` is a postmortem for a
"Data too long" failure thrown mid-transaction, and its docblock credits the
string choice — *"The item statuses are plain string codes, not an enum, so
widening is all that is needed"* — for making the fix a one-line `MODIFY`.

The practical difference: adding a fourth role to an enum needs a schema
migration deployed before any code can emit the new value; adding one to a
string column is a change to a validation rule.

### `aircon_models` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `aircon_model_id` | bigint unsigned PK | |
| `model_name` | varchar(191) | e.g. "TCL 2HP Split Type Inverter" |
| `brand_id` | bigint unsigned, FK → `brand_lookup` | |
| `ac_type_id` | bigint unsigned, FK → `ac_type_lookup` | |
| `capacity_rating` | varchar(20) | Same width as `appliance_details.capacity_rating` |
| `variant` | varchar(100) | Same width as `appliance_details.variant` |
| `is_two_piece` | tinyint(1) | The override; seeded from the AC type's default. Read when saving a model: true requires at least one `indoor` and one `outdoor` unit line, false requires exactly one `complete` line |
| `is_active` | tinyint(1), default 1 | |
| `created_by` / `updated_by` | bigint unsigned, nullable | Matching `product_sets` |
| `created_at` / `updated_at` / `deleted_at` | timestamps | |

### `aircon_model_units` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `aircon_model_unit_id` | bigint unsigned PK | |
| `aircon_model_id` | bigint unsigned, FK → `aircon_models`, cascade on delete | |
| `product_id` | bigint unsigned, FK → `products` | |
| `quantity` | smallint unsigned, default 1 | 3 for the indoor line of a 3:1 multi-split |
| `sort_order` | int, default 0 | |
| | unique(`aircon_model_id`, `product_id`) | |

`unit_role` lives **only** on `appliance_details`, never on the unit line. The
line points at a product and the product already knows which side it is.
Storing the role in both places would create two sources of truth that can
disagree and an invariant to police. It also means a lone outdoor unit sitting
in inventory with no model still reads correctly.

Like `ProductSetLine::product()`, the unit line's product relation uses
`withTrashed()`, so an archived product does not render nameless and price as
zero.

## Screens

### Item Lookups — new "AC Types" panel

`LookupPanel` is fully data-driven from `LOOKUP_CONFIGS`. Adding AC types is one
config entry, one `acTypesAPI` client, one controller, one model, one
migration — no new page and no new rendering logic.

`is_two_piece` uses the `checkbox` field type and `bool` column format, both of
which `LookupPanel` already supports and no config currently uses.

### Product registration — `ProductRegisterModal`

When the selected category is an appliance category, two fields appear after
Capacity and Variant:

- **AC Type** — dropdown from `ac_type_lookup`, optional.
- **Unit Role** — Indoor unit / Outdoor unit / Complete unit.

Selecting an AC type preselects Unit Role from that type's `is_two_piece`:
Window Type preselects Complete; Split Type leaves the choice of side to the
user. This is decision 6 applied at the moment someone actually knows the
answer.

Changing the AC type re-applies that type's default and **discards any manual
Unit Role choice made before the change**. Preserving the prior choice would
silently leave a product marked Complete under a type that means two-piece.
The user can re-pick the side immediately afterwards; the field stays editable.

### Aircon Models page (new)

Modelled on `SetManagementPage` plus `SetBuilderModal` — the same table,
search, archive, and builder-modal shape, and the same permission-gated
create/update/archive actions.

The builder captures the model name, brand, AC type, capacity, and variant,
then unit lines added by picking existing aircon products and setting a
quantity. A standard split is two lines at quantity 1; a multi-split is three
indoor lines and one outdoor line. The builder shows a running total of line
prices, the way `ProductSet::totalPrice()` does.

Routed at `/admin/aircon-models` behind its own permission bundle, alongside
`/admin/sets` — deliberately not inside the Sets page, because a set is a job
BOQ and a model is product identity.

### Convert to two-piece (Item Management row action)

Takes an existing single-row aircon, asks for the indoor and outdoor model
codes and how to split the price, creates the second product row, and builds
the aircon model from the pair.

The original product row **keeps its `product_id`** and becomes one of the two
sides, so every serial, inventory, purchase order, issuance, and warranty
record already pointing at it stays valid.

## Documents

`purchase_order_details`, `issuance_details`, and the receiving detail
equivalent each gain a nullable **`aircon_model_id`**. Lines within one
document sharing a model id render as a group: a header row showing the model
name and the aircon count, with the indoor and outdoor product lines nested
beneath it.

**The header quantity is derived, never stored.** Three aircons is an indoor
line at quantity 3 and an outdoor line at quantity 3; the header shows 3
because 3 ÷ 1 indoor-per-model = 3. For a 3:1 multi-split, ordering two
produces lines of 6 and 2.

This follows from decision 2. When a delivery arrives short and the outdoor
line is received at 2 against an indoor line of 3, the group does not become
invalid — the header renders "2 complete, 1 indoor unpaired" with an imbalance
marker. A stored parent quantity would have to either misreport the delivery or
block it.

Mirroring the `issuance_sets` shape exactly — a parent table per document type
with a stored `set_quantity` and `set_name_snapshot` — was considered and
rejected for that reason, despite being more consistent with what exists. Its
stored parent quantity is the field that goes wrong the moment the two sides
diverge.

**Receiving and serial scanning are unchanged.** Each side is its own product
with its own serials, so staff scan indoor and outdoor cartons exactly as they
scan anything else today. This falls out of the chosen approach at no cost and
was the main reason to prefer it.

## Existing data

208 appliance products exist. 117 have `split-type` in their description and
each is a **single row representing a complete aircon** with a single price;
23 are `window-type`. None have an indoor/outdoor split.

**Chosen migration path:** backfill and provide a conversion tool.

1. A data migration sets `ac_type_id` on existing products by matching
   `products.description` against `split-type`, `window-type`, and the other
   seeded type names, and sets `unit_role = 'complete'` on all 208.
2. New two-piece aircons are registered the new way, as two product rows and a
   model.
3. Staff convert a legacy row when they next receive that model and have the
   real indoor/outdoor codes and prices in hand, using the row action above.

The catalogue is therefore mixed for a period — some split aircons are one row,
some are two — with a path off the old shape.

Automatically splitting all 117 was rejected: it would have to invent a price
split and two model codes that do not exist in the data.

**Verified against the live database, 2026-08-25.** The dump read during
design (`airkingv5.2.sql`) contained product and lookup seed data but no rows
in `item_serial`, `inventory`, `issuance_details`, or `purchase_order_details`.
The live `airkingv5` database does hold history:

| Table | Rows total | Rows against a split-type product |
| --- | --- | --- |
| `item_serial` | 12 | 3 |
| `inventory` | 5 | 1 |
| `issuance_details` | 7 | 1 |
| `purchase_order_details` | 6 | 2 |

The product counts match the dump exactly: 117 split-type, 23 window-type,
208 appliance rows.

This settles the migration choice rather than merely informing it. Legacy
split-type products **are** referenced by serials, stock, an issuance, and
purchase order lines, so the rejected option 2 — automatically splitting all
117 into new product rows — would have orphaned live records. The chosen path
preserves each original `product_id`, so those references stay valid.

## Testing

- **`ac_type_lookup` CRUD** — create, rename, archive; `usageCountFor()`
  reports products using the type; a type in use cannot be hard-deleted.
- **Unit role defaulting** — selecting a two-piece type leaves the side
  unchosen; selecting a one-piece type preselects Complete; changing the type
  after a manual choice discards that choice and re-applies the new default.
- **Model composition** — a 1:1 split and a 3:1 multi-split both build, price,
  and render correctly; archived member products still render by name and price
  via `withTrashed()`. Saving a two-piece model with no outdoor line is
  rejected, as is a one-piece model carrying an indoor line.
- **Derived header quantity** — balanced lines report the correct count; a
  short outdoor line reports the complete count plus the unpaired remainder and
  raises the imbalance marker; a multi-split's non-unit ratio divides correctly.
- **Single-side transactions** — a replacement outdoor unit can be ordered,
  received, and issued with no model reference at all, and does not corrupt any
  group.
- **Backfill migration** — the 117 split-type and 23 window-type rows get the
  right `ac_type_id`; every appliance row gets `unit_role = 'complete'`;
  descriptions matching no known type are left null rather than guessed.
- **Conversion tool** — the original `product_id` is preserved; the price split
  sums to the original price; references to the original product still resolve.

## Known follow-up, out of scope

**Warranty pairing.** `warranty_records` has no way to link an indoor serial to
its partner outdoor serial, which servicing an installed pair requires. It is a
real gap with its own set of questions — which side carries the warranty term,
what happens when one side is replaced under warranty, how a claim on a pair is
recorded. Folding it into this spec would roughly double it. It should be its
own design.
