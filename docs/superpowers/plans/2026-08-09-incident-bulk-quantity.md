# Incident Bulk Quantity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a filer enter an incident quantity as packs + loose pieces, so reporting one damaged box of a 10-per-box consumable writes off 10 pieces instead of 1.

**Architecture:** `quantity` keeps storing PIECES — that is already correct and consistent with inventory, transfers and dispatch. Only entry and display change, reusing the existing `packageFormat` helpers and the two-input pattern `OrderBuilder` already uses. No migration, no strategy change.

**Tech Stack:** Laravel 12 (PHP 8.2+), MySQL/MariaDB via XAMPP, PHPUnit, React 18 (CRA), plain CSS `ak-*`. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-09-incident-bulk-quantity-design.md`

---

## Critical Context For The Implementer

**The bug you are fixing.** `WriteOffResolution:23` reads `(int) $incident->quantity` and decrements `inventory_batches.quantity_remaining` and `inventory.quantity_on_hand` by it. Both are stored in **pieces**. The report form has one unlabelled "Quantity affected" box, so a user reporting one damaged box types `1` and the system writes off **1 piece**. Inventory ends up 9 pieces above reality, silently — the incident resolves fine and the adjustment posts.

**What is NOT wrong.** `packageFormat.js`'s header states the invariant: *inventory, transfers, dispatch and receive all work in PIECES; these helpers only re-express a piece total for display/entry.* So `quantity` holding pieces is right. **Do not change the database, `WriteOffResolution`, `RestockResolution`, or any strategy.** They already operate correctly.

**No migration.** The schema is a SQL dump, not a migration history — do not run bare `php artisan migrate`, and this feature needs none.

**Tests.** `phpunit.xml` points `DB_DATABASE` at `airkingV5_test`; `php artisan test` needs no prefix. Tests use `DatabaseTransactions`, **never** `RefreshDatabase`.

**The suite has 10 known pre-existing failures** — 9 `WarrantyClaimTest`, 1 `SerialRowMovementColumnsTest` — unrelated. "Green" means still exactly those 10. Prefer `--filter=Incident`.

**No frontend test suite.** Verification is `npm run build` plus `npx eslint <files>`, warning-clean *relative to* the known baseline. Anything naming a file you touched is yours.

**~20 files are dirty in the working tree** from unrelated earlier work, including `airkingv5.sql`. Stage only what each task names. Never `git add -A`.

**Git.** PowerShell here-strings break `git commit -m`. Use the Bash heredoc (`git commit -F - <<'EOF'`).

**Facts verified while planning, so you don't have to:**

- `frontend/src/admin/utils/packageFormat.js` exports `piecesToPackages(pieces, ppp) → { boxes, loose }`, `packageBreakdown(pieces, ppp, unit) → "3 Box + 5 pcs" | null`, and `formatPieces(pieces, ppp, unit) → "35 pcs · 3 Box + 5 pcs"`. `packageBreakdown` returns **null** when `ppp <= 1` — that null is the "no pack size" signal every display should branch on.
- `Product` has an **appended** `pieces_per_package` accessor (`getPiecesPerPackageAttribute`, delegating to `installation_material_details`, defaulting to 1). It is in `$appends`, so it is on every product payload.
- `package_unit` is **not** appended, but `ProductController::index` eager-loads `consumableDetail`, so the products list carries it at `consumable_detail.package_unit`. **No model change needed.**
- `IncidentReportController::lookup` hand-builds its `product` object, so it must add both fields explicitly.
- Live reference data: *Copper Tube 1/4 CT (Roll)* — `pieces_per_package` 10, `package_unit` "Box".
- `OrderBuilder.jsx:599-602` is the conversion pattern to mirror.
- Current sites: `IncidentFileModal.jsx:474-483` (the quantity input), `IncidentReportsPage.jsx:180` (`<td>{i.quantity}</td>`), `IncidentResolveDrawer.jsx:130, 146, 173, 268` (confirmations and detail cell).

---

## File Structure

| File | Change |
|---|---|
| `back-end/app/Http/Controllers/Api/IncidentReportController.php` | Add two fields to `lookup()`'s product payload |
| `back-end/tests/Feature/Sets/IncidentScanLookupTest.php` | Assert those fields |
| `frontend/src/admin/components/IncidentFileModal.jsx` | Pack + pieces entry, running total |
| `frontend/src/admin/pages/IncidentReportsPage.jsx` | Quantity column shows the breakdown |
| `frontend/src/admin/components/IncidentResolveDrawer.jsx` | Detail cell + both confirmation dialogs |
| `frontend/src/admin/components/picking/IncidentModal.jsx` | Display only |

---

## Task 1: Serve the pack fields from the lookup

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`
- Test: `back-end/tests/Feature/Sets/IncidentScanLookupTest.php`

- [ ] **Step 1: Write the failing tests**

Append to the class in `back-end/tests/Feature/Sets/IncidentScanLookupTest.php`:

```php
    public function test_the_lookup_returns_pack_size_for_a_consumable(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable(barcode: 'BC-PACK-01');

        // A consumable's pack size lives on installation_material_details;
        // Product appends pieces_per_package as an accessor over it.
        \App\Models\ConsumableDetail::updateOrCreate(
            ['product_id' => $p->product_id],
            ['pieces_per_package' => 10, 'package_unit' => 'Box']
        );

        $this->actingAs($wp, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=BC-PACK-01&location_id={$loc->location_id}")
            ->assertOk()
            ->assertJsonPath('data.product.pieces_per_package', 10)
            ->assertJsonPath('data.product.package_unit', 'Box');
    }

    public function test_the_lookup_reports_no_pack_size_for_an_appliance(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeAppliance(barcode: 'BC-PACK-APP');

        // An appliance has no installation_material_details row, so the accessor
        // falls back to 1 — the client's signal to show a single pieces input.
        $this->actingAs($wp, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=BC-PACK-APP&location_id={$loc->location_id}")
            ->assertOk()
            ->assertJsonPath('data.product.pieces_per_package', 1)
            ->assertJsonPath('data.product.package_unit', null);
    }
```

**Check the model class name before running.** The spec's table is `installation_material_details`; the model may be `ConsumableDetail` (project memory says `ConsumableDetail` maps to that table). Confirm with `ls back-end/app/Models | grep -i consumable` and use the real name.

- [ ] **Step 2: Run to confirm they fail**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
```

Expected: the two new tests fail — `pieces_per_package` is absent from `data.product`.

- [ ] **Step 3: Add the fields**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`, inside `lookup()`'s response, extend the `product` array:

```php
                'product'    => [
                    'product_id'         => (int) $product->product_id,
                    'product_name'       => $product->product_name,
                    'product_code'       => $product->product_code,
                    'barcode'            => $product->barcode,
                    // Packaging, so the form can offer pack + loose-pieces entry
                    // instead of one unlabelled box. pieces_per_package is an
                    // appended accessor on Product (defaults to 1); package_unit
                    // lives on the consumable detail row and is null for
                    // appliances, which is the client's "no pack size" signal.
                    'pieces_per_package' => (int) ($product->pieces_per_package ?? 1),
                    'package_unit'       => $product->consumableDetail?->package_unit,
                ],
```

`lookup()` may not eager-load `consumableDetail`. If the tests show a lazy-load or a null unit for a product that has one, add `->load('consumableDetail')` on `$product` before building the response, and say so in your report.

- [ ] **Step 4: Run to confirm they pass**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
cd back-end && php artisan test --filter=Incident
```

Expected: 16 lookup tests; `--filter=Incident` green.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/tests/Feature/Sets/IncidentScanLookupTest.php
git commit -F - <<'EOF'
feat(incidents): serve pack size from the barcode lookup

The report form needs pieces_per_package and package_unit to offer pack +
loose-pieces entry. The products list already carries both; the lookup
hand-builds its product object, so it had to add them explicitly.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: Pack + pieces entry in the report form

**Files:**
- Modify: `frontend/src/admin/components/IncidentFileModal.jsx`

- [ ] **Step 1: Import the helpers and add state**

Add to the imports:

```jsx
import { packageBreakdown, piecesToPackages } from '../utils/packageFormat';
```

Add beside the existing `quantity` state:

```jsx
  const [packs, setPacks] = useState(0);
  const [loosePieces, setLoosePieces] = useState(0);
```

- [ ] **Step 2: Derive the pack size from the selected product**

Add near the other derived values (after `filteredProducts` is defined):

```jsx
  // pieces_per_package is appended to every product payload and defaults to 1.
  // package_unit lives on the eager-loaded consumable detail, so it is absent
  // for appliances — that absence is the "no pack size" signal.
  const selectedProduct = products.find((p) => String(p.product_id) === String(productId));

  // The products list is capped at 1000, so a SCANNED product may not be in it.
  // The lookup response carries the same two fields, so prefer it when it
  // describes the product currently selected — otherwise a scan of an item
  // outside the cap would silently lose its pack size and under-deduct again,
  // which is the exact bug this change exists to fix.
  const scannedProduct = scanHit?.product
    && String(scanHit.product.product_id) === String(productId)
    ? scanHit.product
    : null;

  const piecesPerPackage = Number(
    scannedProduct?.pieces_per_package ?? selectedProduct?.pieces_per_package
  ) || 0;
  const packageUnit =
    scannedProduct?.package_unit
    || selectedProduct?.consumable_detail?.package_unit
    || 'pkg';
  const usesPacks = piecesPerPackage > 1;

  // The ONE value that is sent. Everything above is entry convenience; the
  // backend stores pieces, as inventory, transfers and dispatch all do.
  const totalPieces = usesPacks
    ? (parseInt(packs, 10) || 0) * piecesPerPackage + (parseInt(loosePieces, 10) || 0)
    : Math.max(0, parseInt(quantity, 10) || 0);
```

**`scanHit` must carry the product for this to work.** In `runLookup`, the object stored in `setScanHit` currently holds `matched_by`, `serial` and `batches` — add `product: d.product` to it. Without that, the fallback above is dead code.

- [ ] **Step 3: Keep the two entry modes in sync**

Add this effect beside the others, so switching product does not strand a stale total:

```jsx
  // Changing product can change the pack size (or remove it). Re-express the
  // current total in the new product's terms rather than silently keeping
  // numbers that now mean something different.
  useEffect(() => {
    if (usesPacks) {
      const { boxes, loose } = piecesToPackages(totalPieces, piecesPerPackage);
      setPacks(boxes);
      setLoosePieces(loose);
    } else {
      setQuantity(totalPieces > 0 ? totalPieces : 1);
    }
    // totalPieces is intentionally omitted: including it would re-run this on
    // every keystroke and fight the user's typing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [productId, piecesPerPackage, usesPacks]);
```

If the linter is satisfied without the disable, remove it — prefer no suppression. Report which you ended up with.

- [ ] **Step 4: Replace the quantity input**

Replace the whole `ak-field` block containing `file-incident-qty` (currently around lines 473–484) with:

```jsx
            <div className="ak-field">
              <label className="ak-label" htmlFor="file-incident-qty">Quantity affected *</label>

              {usesPacks ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
                  <input
                    id="file-incident-qty"
                    className="ak-input"
                    type="number"
                    min={0}
                    value={packs}
                    onChange={(e) => setPacks(e.target.value)}
                    style={{ maxWidth: 90 }}
                    disabled={submitting}
                  />
                  <span className="ak-muted">{packageUnit}</span>
                  <span className="ak-muted">+</span>
                  <input
                    className="ak-input"
                    type="number"
                    min={0}
                    value={loosePieces}
                    onChange={(e) => setLoosePieces(e.target.value)}
                    style={{ maxWidth: 90 }}
                    disabled={submitting}
                  />
                  <span className="ak-muted">pcs</span>
                  <strong style={{ marginLeft: '0.4rem' }}>= {totalPieces} pcs</strong>
                </div>
              ) : (
                <input
                  id="file-incident-qty"
                  className="ak-input"
                  type="number"
                  min={1}
                  value={quantity}
                  onChange={(e) => setQuantity(e.target.value)}
                  style={{ maxWidth: 160 }}
                  disabled={submitting}
                />
              )}

              {usesPacks && (
                <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                  1 {packageUnit} = {piecesPerPackage} pcs. Stock is written off in pieces.
                </span>
              )}
            </div>
```

- [ ] **Step 5: Send the computed total**

In `handleSubmit`'s payload, replace `quantity: needsStock ? Number(quantity) : null,` with:

```jsx
        quantity: needsStock ? totalPieces : null,
```

And in `canSubmit`, replace the `Number(quantity) >= 1` term so the guard follows the real value:

```jsx
    && (!needsStock || (!!productId && totalPieces >= 1));
```

Add `totalPieces` to `handleSubmit`'s `useCallback` dependency array and remove `quantity` from it if present.

- [ ] **Step 6: Reset the new state on open**

In the reset-on-open effect, beside `setQuantity(1);`, add:

```jsx
    setPacks(0);
    setLoosePieces(0);
```

- [ ] **Step 7: Verify**

```bash
cd frontend && npx eslint src/admin/components/IncidentFileModal.jsx
cd frontend && npm run build
```

Expected: eslint exit 0; `Compiled` with no warning naming `IncidentFileModal`.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/components/IncidentFileModal.jsx
git commit -F - <<'EOF'
fix(incidents): enter quantity as packs + loose pieces

Reporting one damaged box of a 10-per-box consumable wrote off 1 piece,
because the form had a single unlabelled quantity box and the backend
stores pieces. Entry now mirrors OrderBuilder: two inputs when the product
has a pack size, one when it does not, converted to pieces at submit. The
running total is shown so the filer sees exactly what will be reported.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: Show the breakdown wherever a quantity is read

**Files:**
- Modify: `frontend/src/admin/pages/IncidentReportsPage.jsx`
- Modify: `frontend/src/admin/components/IncidentResolveDrawer.jsx`
- Modify: `frontend/src/admin/components/picking/IncidentModal.jsx`

The API already returns `product_id` but **not** the pack size on incident rows, so these surfaces cannot compute a breakdown from the incident alone. Rather than widen the incident serializer, each surface derives it from data it already has.

- [ ] **Step 1: Add the pack size to the incident list payload**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`, in `index()`'s `map()` closure, directly after `'product_name'`:

```php
                // Packaging so the list, drawer and confirmations can show a
                // pack breakdown. pieces_per_package is an appended accessor
                // (defaults to 1); package_unit is null for appliances.
                'pieces_per_package'      => (int) ($i->product?->pieces_per_package ?? 1),
                'package_unit'            => $i->product?->consumableDetail?->package_unit,
```

`index()` already eager-loads `product`. Add `consumableDetail` to that eager load so this does not N+1:

```php
        $query = IncidentReport::with(['product.consumableDetail', 'batch', 'location', 'reportedBy', 'reviewedBy', 'relatedIssuance'])
```

- [ ] **Step 2: Verify the payload**

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: green. The existing tests do not assert these keys; adding them is additive.

- [ ] **Step 3: Show it in the list**

In `frontend/src/admin/pages/IncidentReportsPage.jsx`, add the import:

```jsx
import { packageBreakdown } from '../utils/packageFormat';
```

Replace the quantity cell (`<td>{i.quantity}</td>`, around line 180) with:

```jsx
                      <td>
                        {i.quantity} pcs
                        {packageBreakdown(i.quantity, i.pieces_per_package, i.package_unit) && (
                          <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                            · {packageBreakdown(i.quantity, i.pieces_per_package, i.package_unit)}
                          </span>
                        )}
                      </td>
```

`packageBreakdown` returns null when there is no pack size, so appliances render just `3 pcs`.

- [ ] **Step 4: Show it in the drawer's detail grid**

In `frontend/src/admin/components/IncidentResolveDrawer.jsx`, add the import:

```jsx
import { formatPieces } from '../utils/packageFormat';
```

Replace the quantity cell (`<div>{incident.quantity}</div>`, around line 173) with:

```jsx
            <div>{formatPieces(incident.quantity, incident.pieces_per_package, incident.package_unit)}</div>
```

- [ ] **Step 5: Name the breakdown in both confirmation dialogs**

Still in `IncidentResolveDrawer.jsx`. This is the change that matters most — a reviewer approving a permanent write-off must see what they are approving.

Add near the top of the component, after `outcome` is computed:

```jsx
  // "25 pcs · 2 Box + 5 pcs", or just "25 pcs" without a pack size.
  const qtyLabel = formatPieces(incident.quantity, incident.pieces_per_package, incident.package_unit);
```

In `handleConfirm`'s write-off dialog, replace the `text:` line with:

```jsx
        text: `This writes stock off — it posts a deduction adjustment for ${qtyLabel} of "${incident.product_name}". This cannot be undone from here.`,
```

In the restock dialog, replace its `text:` line with:

```jsx
        text: `This returns ${qtyLabel} of "${incident.product_name}" to ${where} and makes them available again.`,
```

And in the inline restock paragraph, replace `<strong>{incident.quantity}</strong> unit(s)` with:

```jsx
                <strong>{qtyLabel}</strong>
```

- [ ] **Step 6: Show it in the picking modal**

In `frontend/src/admin/components/picking/IncidentModal.jsx`, add the import:

```jsx
import { packageBreakdown } from '../../utils/packageFormat';
```

Directly under its quantity input, add:

```jsx
          {packageBreakdown(quantity, context.pieces_per_package, context.package_unit) && (
            <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
              = {packageBreakdown(quantity, context.pieces_per_package, context.package_unit)}
            </span>
          )}
```

**Entry here is deliberately unchanged** — this modal's quantity comes from the FIFO pick plan, already in pieces and already correct. If `context` does not carry `pieces_per_package`, the breakdown simply never renders; do **not** add a fetch for it, and note in your report whether the pick plan supplies it.

- [ ] **Step 7: Verify**

```bash
cd frontend && npx eslint src/admin/pages/IncidentReportsPage.jsx src/admin/components/IncidentResolveDrawer.jsx src/admin/components/picking/IncidentModal.jsx
cd frontend && npm run build
```

Expected: eslint exit 0; `Compiled` with no warning naming any of the three.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IncidentReportController.php frontend/src/admin/pages/IncidentReportsPage.jsx frontend/src/admin/components/IncidentResolveDrawer.jsx frontend/src/admin/components/picking/IncidentModal.jsx
git commit -F - <<'EOF'
feat(incidents): show the pack breakdown wherever a quantity is read

A reviewer approving a permanent write-off saw a bare number, with no way
to tell 20 pieces from 20 boxes — the same ambiguity that caused the
under-deduction, moved to the approval step. The list, the drawer and both
confirmation dialogs now read "25 pcs · 2 Box + 5 pcs". The picking modal
gets the display only; its quantity comes from the pick plan.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: Verification

- [ ] **Step 1: Backend**

```bash
cd back-end && php artisan test --filter=Incident
cd back-end && php artisan test
```

Expected: incident suite green. Full suite: **exactly 10 failures**, the known `WarrantyClaimTest` (9) and `SerialRowMovementColumnsTest` (1).

- [ ] **Step 2: Frontend**

```bash
cd frontend && npm run build
cd frontend && npx eslint src/admin/components/IncidentFileModal.jsx src/admin/pages/IncidentReportsPage.jsx src/admin/components/IncidentResolveDrawer.jsx src/admin/components/picking/IncidentModal.jsx
```

Expected: `Compiled`; eslint exit 0 with no suppressions beyond the one in Task 2 Step 3, if it proved necessary.

- [ ] **Step 3: The decisive manual check**

This reproduces the original bug. Serve at `http://localhost:3000`.

1. Note the current on-hand pieces for a **10-per-box** consumable (*Copper Tube 1/4 CT (Roll)* is 10 per Box) at a location.
2. Report Incident → reason **Damaged** → pick that product.
3. The quantity field shows **two** inputs with the unit label and a running total.
4. Enter **1 Box + 0 pcs**. The total reads **10 pcs**.
5. File it.
6. The incident list shows `10 pcs · 1 Box`.
7. Open it as an admin or inventory analyst → the write-off confirmation says **10 pcs · 1 Box**, not "10 unit(s)".
8. Confirm.
9. **Check inventory: on-hand dropped by 10, not 1.** This is the fix.

Then the negative case:

10. Report an incident for an **appliance**. The quantity field is a **single** input, unchanged, and the list shows just `1 pcs` with no breakdown.

- [ ] **Step 4: Commit any fixes**

If the walkthrough found defects, fix them, re-run Steps 1 and 2, and commit. If it found none, there is nothing to commit and this plan is complete.

---

## Notes For Integration

`backupV3` is pushed and current. Do not merge to `main` without asking — decide integration separately using `superpowers:finishing-a-development-branch`.
