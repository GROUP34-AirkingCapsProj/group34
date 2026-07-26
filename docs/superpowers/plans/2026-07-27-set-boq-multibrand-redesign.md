# Set (BOQ) Multi-Brand Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use `- [ ]` checkboxes.

**Goal:** Make a Set = installation-materials BOQ identified by many brands + one capacity + one type (inverter/non-inverter), with pack/box piece quantities like Transfers; drop set code/name and auto-suggest.

**Architecture:** New `product_set_brands` pivot replaces the single `brand_id`; `set_code`/`set_name`/`description` and `product_set_lines.issuable_unit_id` are dropped. Controller/models/UI switch to brand lists + pack/box pieces. The `suggest()` endpoint is removed; Sets are picked manually on issue-out.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL via XAMPP, PHPUnit + `DatabaseTransactions` (`airkingV5_test`), React 18 CRA, `ak-*` design system.

**Spec:** `docs/superpowers/specs/2026-07-27-set-boq-multibrand-redesign-design.md`

---

## Conventions
- Backend root `back-end`; frontend root `frontend`. Migrations one at a time with `--path --force`; idempotent; apply to BOTH `airkingV5` and `airkingV5_test`.
- `php artisan test` targets `airkingV5_test`. `DatabaseTransactions`, never `RefreshDatabase`.
- Roles/auth: `actingAs($user,'sanctum')`. Sets CRUD gated `product_set_management` (read all roles; create/update/archive admin).
- Frontend has no test suite — verify `npm run build` (no NEW warnings from changed files); note manual `npm run demo`.
- Commit per task; do NOT push.

---

## Task 1: Schema — brands pivot; drop code/name; drop line issuable_unit

**Files:** create `database/migrations/2026_07_27_000001_redesign_product_sets_multibrand.php`; modify `tests/Feature/Sets/SetSchemaTest.php`.

- [ ] **Step 1: Write the idempotent migration.**
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('product_set_brands')) {
            Schema::create('product_set_brands', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('set_id');
                $t->unsignedBigInteger('brand_id');
                $t->timestamps();
                $t->unique(['set_id', 'brand_id']);
                $t->foreign('set_id')->references('set_id')->on('product_sets')->cascadeOnDelete();
                $t->foreign('brand_id')->references('brand_id')->on('brand_lookup')->cascadeOnDelete();
            });
        }

        // Migrate any existing single brand_id into the pivot before dropping it.
        if (Schema::hasColumn('product_sets', 'brand_id')) {
            $rows = DB::table('product_sets')->whereNotNull('brand_id')->get(['set_id', 'brand_id']);
            foreach ($rows as $r) {
                DB::table('product_set_brands')->updateOrInsert(
                    ['set_id' => $r->set_id, 'brand_id' => $r->brand_id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        Schema::table('product_sets', function (Blueprint $t) {
            // Drop the composite index that references brand_id first.
            try { $t->dropIndex('product_sets_match_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('product_sets', function (Blueprint $t) {
            if (Schema::hasColumn('product_sets', 'brand_id')) {
                try { $t->dropForeign(['brand_id']); } catch (\Throwable $e) {}
                $t->dropColumn('brand_id');
            }
            if (Schema::hasColumn('product_sets', 'set_code'))    $t->dropColumn('set_code');   // drops its unique index too
            if (Schema::hasColumn('product_sets', 'set_name'))    $t->dropColumn('set_name');
            if (Schema::hasColumn('product_sets', 'description')) $t->dropColumn('description');
        });

        Schema::table('product_set_lines', function (Blueprint $t) {
            if (Schema::hasColumn('product_set_lines', 'issuable_unit_id')) {
                try { $t->dropForeign(['issuable_unit_id']); } catch (\Throwable $e) {}
                $t->dropColumn('issuable_unit_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_sets', function (Blueprint $t) {
            if (! Schema::hasColumn('product_sets', 'set_code')) $t->string('set_code', 60)->nullable();
            if (! Schema::hasColumn('product_sets', 'set_name')) $t->string('set_name', 191)->nullable();
        });
        Schema::dropIfExists('product_set_brands');
    }
};
```
Note: if `dropColumn('set_code')` errors because the unique index must be dropped explicitly on this MySQL/MariaDB version, add `try { $t->dropUnique('product_sets_set_code_unique'); } catch (\Throwable $e) {}` before the column drop. Verify the real index name with `SHOW INDEX FROM product_sets` if the drop fails.

- [ ] **Step 2: Apply to both DBs.**
```bash
php artisan migrate --force --path=database/migrations/2026_07_27_000001_redesign_product_sets_multibrand.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_27_000001_redesign_product_sets_multibrand.php
```
Expected DONE twice. If it fails on an index/FK name, fix the migration and re-run (idempotent guards make re-runs safe).

- [ ] **Step 3: Update `SetSchemaTest`.** Replace assertions for `set_code`/`set_name`/`brand_id` columns with: `product_sets` has `capacity_rating`, `variant`, `is_active`, `deleted_at` and does NOT have `set_code`/`set_name`/`brand_id`; `product_set_brands` exists with `set_id`,`brand_id`; `product_set_lines` does NOT have `issuable_unit_id`. Use `Schema::hasColumn` / `Schema::hasTable`.

- [ ] **Step 4: Run** `php artisan test --filter=SetSchemaTest` → PASS.
- [ ] **Step 5: Commit** `feat(sets): product_set_brands pivot; drop set code/name and line issuable_unit`.

---

## Task 2: Models — ProductSet.brands()/label(); ProductSetLine pieces

**Files:** modify `app/Models/ProductSet.php`, `app/Models/ProductSetLine.php`; update `tests/Feature/Sets/ProductSetModelTest.php`.

- [ ] **Step 1: `ProductSet`.** Remove `set_code`,`set_name`,`description`,`brand_id` from `$fillable` (keep `capacity_rating`,`variant`,`is_active`,`created_by`,`updated_by`). Remove the `brand()` belongsTo. Add:
```php
public function brands(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
{
    return $this->belongsToMany(BrandLookup::class, 'product_set_brands', 'set_id', 'brand_id');
}

/** Derived display label: "TCL, Carrier · 2HP · Non-inverter". */
public function label(): string
{
    $this->loadMissing('brands');
    $brands = $this->brands->pluck('brand_name')->filter()->implode(', ');
    return collect([$brands ?: 'Any brand', $this->capacity_rating, $this->variant])
        ->filter()->implode(' · ');
}
```
Keep `lines()` and `totalPrice()`.

- [ ] **Step 2: `ProductSetLine`.** Remove the `issuableUnit()` relation and `issuable_unit_id` from `$fillable`. Make `quantityInPieces()` return the integer piece quantity directly:
```php
public function quantityInPieces(): int
{
    return (int) round((float) $this->quantity);
}
```
(Confirm `quantity` cast stays `float`/`decimal`.)

- [ ] **Step 3: Update `ProductSetModelTest`.** Replace `ProductSet::create(['set_code'=>…,'set_name'=>…])` with `ProductSet::create(['capacity_rating'=>'2HP','variant'=>'non-inverter'])`. Add a test: attach two brands via `$set->brands()->sync([$b1,$b2])` and assert `label()` contains both brand names + capacity + variant. Keep the price + cascade-delete tests (adjust creation args).

- [ ] **Step 4: Run** `php artisan test --filter=ProductSetModelTest` → PASS.
- [ ] **Step 5: Commit** `feat(sets): ProductSet.brands() + derived label; line quantity is pieces`.

---

## Task 3: Controller — brand_ids + pack/box; remove suggest

**Files:** modify `app/Http/Controllers/Api/ProductSetController.php`, `routes/api.php`; update `tests/Feature/Sets/ProductSetCrudTest.php`; delete `tests/Feature/Sets/SetSuggestionTest.php`.

- [ ] **Step 1: `validatePayload`.** Drop `set_code`,`set_name`,`description`,`brand_id`,`lines.*.issuable_unit_id`. Add `brand_ids` = `required|array|min:1` (or `sometimes` on update), `brand_ids.*` = `integer|exists:brand_lookup,brand_id`. Keep `capacity_rating`,`variant` (`variant` may be constrained to `inverter,non-inverter` via `Rule::in`). Lines: `product_id` required exists, `quantity` `required|integer|min:1`, `spec_note` nullable, `sort_order` nullable.

- [ ] **Step 2: `store`/`update`.** Stop writing `set_code`/`set_name`/`description`/`brand_id`. After create/update, `->brands()->sync($validated['brand_ids'] ?? [])`. Keep line sync (drop `issuable_unit_id`). Load `['lines.product','brands']` for the response.

- [ ] **Step 3: `present()`.** Return `set_id`, `capacity_rating`, `variant`, `is_active`, `set_price` (`totalPrice()`), `label` (`$set->label()`), `brands` (`$set->brands->map(fn($b)=>['brand_id'=>(int)$b->brand_id,'brand_name'=>$b->brand_name])`), `brand_names` (joined). For each line: `set_line_id`,`product_id`,`product_name`,`product_type`,`quantity` (pieces, int), `pieces_per_package` (`$line->product?->pieces_per_package ?? 0`), `package_unit` (`$line->product?->consumableDetail?->package_unit`), `spec_note`,`sort_order`,`unit_price`,`line_total` (`quantity * unit_price`). Drop `issuable_unit_id`/`unit_label`/`quantity_in_pieces`.

- [ ] **Step 4: Remove `suggest()`** from the controller and its route in `routes/api.php` (the `/product-sets/suggest` GET). Also remove the frontend `productSetsAPI.suggest` (in Task 7 if not already unused — grep; the OrderBuilder no longer calls it after earlier cleanup).

- [ ] **Step 5: Update `ProductSetCrudTest`.** Rewrite the create/update payloads: replace `set_code`/`set_name` with `brand_ids` + `capacity_rating` + `variant`; assert the response `data.brands` has the expected brand ids, `data.label` is derived, `data.set_price` computes, and lines round-trip with `quantity` pieces (no issuable_unit). Add a case: update replaces the brand list (sync). Remove the duplicate-set_code test.

- [ ] **Step 6: Delete `SetSuggestionTest.php`** (`git rm`).

- [ ] **Step 7: Run** `php artisan test --filter=ProductSetCrudTest` → PASS.
- [ ] **Step 8: Commit** `feat(sets): CRUD on brand list + pack/box pieces; remove suggest`.

---

## Task 4: Green the rest of the backend suite

**Files:** `tests/Feature/Sets/DraftLineEndpointsTest.php`, `IssuanceOrderSchemaTest.php`, `IssuanceOrderServiceTest.php`, `IssuanceQuoteTest.php`, `IssuanceSetModelTest.php`, `SetLineConversionTest.php`; possibly `app/Services/IssuanceOrderService.php`.

- [ ] **Step 1: Fix fixtures.** In each file, replace `ProductSet::create(['set_code'=>…,'set_name'=>…, 'brand_id'=>…])` with `ProductSet::create(['capacity_rating'=>…,'variant'=>…])` (+ `->brands()->sync([...])` where a brand matters). Remove `issuable_unit_id` from any `lines()->create([...])` and from `ProductIssuableUnit` usage; set `quantity` in pieces.

- [ ] **Step 2: `SetLineConversionTest`.** This tested issuable-unit conversion, which is gone. Rewrite it to assert `quantityInPieces()` returns the integer piece quantity (e.g. quantity 7 → 7; 1.5 → 2 via round) — or delete it if it no longer tests anything meaningful. Choose based on what survives.

- [ ] **Step 3: `IssuanceOrderService::addSet`.** Confirm it expands a set line using the piece `quantity` (× set_quantity) and does NOT call the removed issuable-unit conversion. Read the method; if it referenced `issuable_unit_id`/`quantityInPieces()` conversion, adjust to the plain piece quantity. Keep its tests (`IssuanceOrderServiceTest`) green.

- [ ] **Step 4: Run the whole suite** `php artisan test` → green (was 615 passed / 1 skipped; expect similar minus the deleted suggest tests plus any added). Report the line.
- [ ] **Step 5: Commit** `test(sets): migrate remaining fixtures to multi-brand pack/box shape`.

---

## Task 5: SetManagementPage — new columns

**Files:** `frontend/src/admin/pages/SetManagementPage.jsx`.

- [ ] **Step 1:** Table columns → **Brands** (chips from `s.brands`/`brand_names`), **Capacity**, **Type** (variant), **Lines** (count), **Set price**, Status, actions. Remove Code/Name columns and any `set_code`/`set_name` reads. Duplicate/edit pass the new shape; drop `set_code` reset logic. Search stays (brand/capacity/type text).
- [ ] **Step 2:** Build → clean, no new warnings. Commit `feat(sets): Set Management list shows brands/capacity/type (no code/name)`.

---

## Task 6: SetBuilderModal — multi-brand, type, capacity, pack/box

**Files:** `frontend/src/admin/components/SetBuilderModal.jsx`.

- [ ] **Step 1:** Remove the `set_code`/`set_name` inputs and their validation. Add:
  - **Brands** multi-select — a checkbox list (or multi-select) of `brands` (already passed as a prop). Store `brand_ids` array.
  - **Type** dropdown: Inverter / Non-inverter (maps to `variant`).
  - **Capacity** dropdown: load distinct capacities (from an appliance/capacity source — reuse whatever ItemManagement/product forms use, or a distinct list from `productsAPI`/appliance details; fall back to a free-text input if no lookup exists). Store `capacity_rating`.
- [ ] **Step 2:** Material line editor: replace the issuable-unit dropdown with **packages** + **loose pieces** number inputs using the product's `pieces_per_package`/`package_unit` (read from the product object; import `packageBreakdown`/`piecesToPackages` from `utils/packageFormat`). Compute stored `quantity` in pieces = `packages*ppp + pieces` (or just pieces when ppp<=1). Show the "N pcs · X Box + Y pcs" breakdown. Keep spec note. Make the product dropdown + inputs **compact** (constrained widths) so line columns stay visible.
- [ ] **Step 3:** On save, POST/PUT `{ brand_ids, capacity_rating, variant, lines:[{product_id, quantity(pieces), spec_note, sort_order}] }`. Live set-price total still shown.
- [ ] **Step 4:** Build clean. Commit `feat(sets): builder with multi-brand, type/capacity dropdowns, pack/box line qty`.

---

## Task 7: Issue-out — pack/box display + compact dropdowns

**Files:** `frontend/src/admin/components/issuance/OrderBuilder.jsx` (+ `QuotePanel.jsx` if it renders material qty).

- [ ] **Step 1:** In the order line list (and QuotePanel if applicable), show material quantities with the pack/box breakdown (`packageBreakdown(qty, pieces_per_package, package_unit)`) consistent with Transfers, when the line's product has a package size.
- [ ] **Step 2:** Make the AddCustomPath Category/Brand/Product dropdowns **compact** (cap widths, e.g. `maxWidth`/smaller flex-basis; truncate long product option text) so they don't hide other columns.
- [ ] **Step 3:** Confirm no residual `productSetsAPI.suggest` call remains (grep; remove if any). "Add set" (manual) + "Add custom" unchanged otherwise.
- [ ] **Step 4:** Build clean. Commit `feat(issuance): pack/box material quantities; compact custom-item dropdowns`.

---

## Done Criteria
- A Set has many brands + one capacity + one type, no code/name; label is derived; price calculated.
- Set builder + issue-out material quantities use pack/box + pieces like Transfers.
- No auto-suggest; suggest endpoint/tests removed.
- Dropdowns compact in both the Set builder and issue-out custom-item.
- Backend suite green; frontend builds clean; manual demo pass noted.
