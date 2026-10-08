# Location-scoped PO Receiving Totals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the receiving screen, show each PO card's total amount and item count scoped to the receiver's own warehouse; admins keep the whole-PO figure plus a per-warehouse breakdown.

**Architecture:** Extract the PO line-scoping + money math into a small pure util module (`poReceivingTotals.js`) so it is unit-testable without importing the heavy `BarcodeScan.jsx` page. `BarcodeScan.jsx` imports these helpers, replaces the local `bsrLinesForLocation` definition with the shared one, and uses the new totals in the PO list card. No backend change.

**Tech Stack:** React 18 (CRA / react-scripts), Jest, plain CSS.

---

## Background (read before starting)

- Each PO line in the list payload already carries `subtotal` and `location_id`
  (backend `PurchaseOrderController::index()` runs `transformPoDetail` on every line).
  No backend change is needed.
- `frontend/src/admin/pages/BarcodeScan.jsx` currently defines a local helper
  `bsrLinesForLocation(po, locationId)` (~line 501) used in several places
  (~lines 1682, 1919, 2845) and in the PO list card (~lines 2804-2835).
- `myLocationId` (defined ~line 551) is the receiver's enforced warehouse id as a
  String, or `null` for centralized/admin users.
- `enforcedLocationName` comes from `useUserLocation()` (destructured ~line 545).
- Currency formatter `bsrFmtCurrency` exists (~line 475).
- CSS lives in `frontend/src/admin/styles/po_receiving.css` (card classes ~line 445).

Spec: `docs/superpowers/specs/2026-07-14-location-scoped-po-receiving-totals-design.md`.

---

## File Structure

- **Create** `frontend/src/admin/utils/poReceivingTotals.js` — pure helpers:
  `linesForLocation`, `lineMoney`, `locationAmount`, `locationItemCount`,
  `warehouseBreakdown`. Single source of truth for PO line scoping + money.
- **Create** `frontend/src/admin/utils/poReceivingTotals.test.js` — Jest unit tests.
- **Modify** `frontend/src/admin/pages/BarcodeScan.jsx` — import from the util, remove
  the local `bsrLinesForLocation` definition, use new totals in the PO list card.
- **Modify** `frontend/src/admin/styles/po_receiving.css` — styles for the scope
  clarifier line and the admin breakdown row.

---

### Task 1: Pure totals util + tests

**Files:**
- Create: `frontend/src/admin/utils/poReceivingTotals.js`
- Test: `frontend/src/admin/utils/poReceivingTotals.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/poReceivingTotals.test.js`:

```js
import {
  linesForLocation,
  lineMoney,
  locationAmount,
  locationItemCount,
  warehouseBreakdown,
} from './poReceivingTotals';

// A multi-warehouse PO: 2 lines for warehouse "10", 1 line for warehouse "20".
const multiPo = {
  location_id: 10,
  total_amount: 300,
  details: [
    { po_detail_id: 1, location_id: 10, location_name: 'Warehouse A', quantity_ordered: 2, unit_price: 50, subtotal: 100 },
    { po_detail_id: 2, location_id: 10, location_name: 'Warehouse A', quantity_ordered: 1, unit_price: 50, subtotal: 50 },
    { po_detail_id: 3, location_id: 20, location_name: 'Warehouse B', quantity_ordered: 3, unit_price: 50, subtotal: 150 },
  ],
};

// Legacy single-warehouse PO: lines have no location_id, fall back to header.
const legacyPo = {
  location_id: 10,
  total_amount: 80,
  details: [
    { po_detail_id: 9, quantity_ordered: 4, unit_price: 20, subtotal: 80 },
  ],
};

describe('poReceivingTotals', () => {
  test('lineMoney uses subtotal, falls back to qty * unit_price', () => {
    expect(lineMoney({ subtotal: 100 })).toBe(100);
    expect(lineMoney({ quantity_ordered: 3, unit_price: 50 })).toBe(150);
    expect(lineMoney({})).toBe(0);
  });

  test('linesForLocation filters to the given warehouse', () => {
    expect(linesForLocation(multiPo, '10')).toHaveLength(2);
    expect(linesForLocation(multiPo, '20')).toHaveLength(1);
  });

  test('linesForLocation returns all lines when locationId is null (admin)', () => {
    expect(linesForLocation(multiPo, null)).toHaveLength(3);
  });

  test('legacy lines without location_id fall back to header location_id', () => {
    expect(linesForLocation(legacyPo, '10')).toHaveLength(1);
    expect(locationAmount(legacyPo, '10')).toBe(80);
  });

  test('locationAmount sums only the warehouse slice', () => {
    expect(locationAmount(multiPo, '10')).toBe(150);
    expect(locationAmount(multiPo, '20')).toBe(150);
    expect(locationAmount(multiPo, null)).toBe(300);
  });

  test('locationItemCount counts only the warehouse slice', () => {
    expect(locationItemCount(multiPo, '10')).toBe(2);
    expect(locationItemCount(multiPo, null)).toBe(3);
  });

  test('warehouseBreakdown groups and sums per warehouse', () => {
    const b = warehouseBreakdown(multiPo);
    expect(b).toHaveLength(2);
    const a = b.find((x) => x.locationName === 'Warehouse A');
    const c = b.find((x) => x.locationName === 'Warehouse B');
    expect(a).toMatchObject({ amount: 150, count: 2 });
    expect(c).toMatchObject({ amount: 150, count: 1 });
  });

  test('warehouseBreakdown yields one entry for a single-warehouse PO', () => {
    expect(warehouseBreakdown(legacyPo)).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test src/admin/utils/poReceivingTotals.test.js --watchAll=false`
Expected: FAIL — `Cannot find module './poReceivingTotals'`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/admin/utils/poReceivingTotals.js`:

```js
// Pure helpers for scoping a purchase order's totals to a single destination
// warehouse. A PO carries one supplier but may have lines for several warehouses;
// a receiver at one warehouse should only see the money/items for their own lines.
// Kept dependency-free so it is unit-testable without importing the receiving page.

/** Money for one PO line: explicit subtotal, else quantity * unit price, else 0. */
export const lineMoney = (d) => {
  const qty = Number(d?.quantity_ordered ?? d?.quantity ?? d?.quantity_amount ?? 0);
  const price = Number(d?.unit_price ?? 0);
  return Number(d?.subtotal ?? qty * price) || 0;
};

/**
 * Lines on a PO destined for a given warehouse. Matches each line's `location_id`,
 * falling back to the PO header `location_id` for legacy single-warehouse POs whose
 * lines predate per-line warehouses. Pass `locationId` null/empty for centralized /
 * admin users who are not locked to one warehouse — they get every line.
 */
export const linesForLocation = (po, locationId) => {
  const details = Array.isArray(po?.details) ? po.details : [];
  if (!locationId) return details;
  return details.filter(
    (d) => String(d.location_id ?? po?.location_id) === String(locationId),
  );
};

/** Sum of line money for a PO's lines destined for `locationId` (all lines if null). */
export const locationAmount = (po, locationId) =>
  linesForLocation(po, locationId).reduce((s, d) => s + lineMoney(d), 0);

/** Count of a PO's lines destined for `locationId` (all lines if null). */
export const locationItemCount = (po, locationId) =>
  linesForLocation(po, locationId).length;

/**
 * Per-warehouse breakdown of a PO: [{ locationId, locationName, amount, count }].
 * Used for the admin/centralized card view so distribution is visible at a glance.
 */
export const warehouseBreakdown = (po) => {
  const details = Array.isArray(po?.details) ? po.details : [];
  const map = new Map();
  details.forEach((d) => {
    const key = String(d.location_id ?? po?.location_id ?? '__none__');
    const name =
      d.location_name || d.location?.location_name || 'Unassigned warehouse';
    if (!map.has(key)) {
      map.set(key, { locationId: key, locationName: name, amount: 0, count: 0 });
    }
    const g = map.get(key);
    g.amount += lineMoney(d);
    g.count += 1;
  });
  return Array.from(map.values());
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test src/admin/utils/poReceivingTotals.test.js --watchAll=false`
Expected: PASS — all tests green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/poReceivingTotals.js frontend/src/admin/utils/poReceivingTotals.test.js
git commit -m "feat(po-receiving): add pure per-warehouse PO totals helpers

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Route BarcodeScan through the shared line-scoping helper

Remove the duplicated `bsrLinesForLocation` definition in `BarcodeScan.jsx` and import
the shared `linesForLocation` under the same name, so all existing call sites keep
working through one source of truth.

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`

- [ ] **Step 1: Add the import**

Near the other util imports at the top of `frontend/src/admin/pages/BarcodeScan.jsx`,
add:

```js
import {
  linesForLocation as bsrLinesForLocation,
  locationAmount as bsrLocationAmount,
  locationItemCount as bsrLocationItemCount,
  warehouseBreakdown as bsrWarehouseBreakdown,
} from '../utils/poReceivingTotals';
```

- [ ] **Step 2: Delete the local `bsrLinesForLocation` definition**

Remove the local definition (and its JSDoc comment) at ~lines 495-505:

```js
/**
 * Lines on a PO destined for a given warehouse — a receiver at Warehouse A should only see/receive
 * ...
 */
const bsrLinesForLocation = (po, locationId) => {
  const details = Array.isArray(po?.details) ? po.details : [];
  if (!locationId) return details;
  return details.filter((d) => String(d.location_id ?? po?.location_id) === String(locationId));
};
```

Leave every *call* to `bsrLinesForLocation(...)` untouched — the import aliases the
shared helper to the same name.

- [ ] **Step 3: Verify the app still builds**

Run: `cd frontend && npx eslint src/admin/pages/BarcodeScan.jsx`
Expected: no `no-undef` / `no-unused-vars` errors for `bsrLinesForLocation`.

(If eslint is not configured to run standalone, instead run
`cd frontend && CI=true npm run build` and expect a successful compile with no
"bsrLinesForLocation is not defined" error.)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "refactor(po-receiving): source line-scoping from poReceivingTotals util

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Scope the PO list card total + item count; add admin breakdown

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (card render ~lines 2804-2835)
- Modify: `frontend/src/admin/styles/po_receiving.css`

- [ ] **Step 1: Compute scoped figures inside the card map**

In the `bsrFilteredPos.map((po) => { ... })` block, replace the current line:

```js
const totalAmt = Number(po.total_amount || po.grand_total || 0);
```

with:

```js
const scoped    = !!myLocationId;
const totalAmt  = scoped
  ? bsrLocationAmount(po, myLocationId)
  : Number(po.total_amount || po.grand_total || 0);
const itemCount = scoped ? bsrLocationItemCount(po, myLocationId) : bsrGetItemCount(po);
const breakdown = scoped ? [] : bsrWarehouseBreakdown(po);
const showBreakdown = !scoped && breakdown.length > 1;
```

- [ ] **Step 2: Add the scope clarifier under the amount**

Replace the amount block:

```jsx
<div className="bsr-po-amount">
  <span className="bsr-amount-label">TOTAL AMOUNT</span>
  <span className="bsr-amount-value">{bsrFmtCurrency(totalAmt)}</span>
</div>
```

with:

```jsx
<div className="bsr-po-amount">
  <span className="bsr-amount-label">TOTAL AMOUNT</span>
  <span className="bsr-amount-value">{bsrFmtCurrency(totalAmt)}</span>
  {scoped && (
    <span className="bsr-amount-scope">for {enforcedLocationName || 'your warehouse'}</span>
  )}
</div>
```

- [ ] **Step 3: Use the scoped item count**

Replace the ITEMS value line:

```jsx
<span className="bsr-meta-value">{typeof bsrGetItemCount(po) === 'number' ? `${bsrGetItemCount(po)} items` : '—'}</span>
```

with:

```jsx
<span className="bsr-meta-value">{typeof itemCount === 'number' ? `${itemCount} items` : '—'}</span>
```

- [ ] **Step 4: Add the admin breakdown row**

Immediately after the closing `</div>` of `bsr-po-card-meta` (before the card
button's closing `</button>`), add:

```jsx
{showBreakdown && (
  <div className="bsr-po-breakdown">
    {breakdown.map((b) => (
      <span key={b.locationId} className="bsr-breakdown-chip">
        {b.locationName} <strong>{bsrFmtCurrency(b.amount)}</strong>
      </span>
    ))}
  </div>
)}
```

- [ ] **Step 5: Add CSS**

Append to `frontend/src/admin/styles/po_receiving.css`:

```css
.bsr-amount-scope {
  display: block; font-size: 11px; font-weight: 600;
  color: #9ca3af; margin-top: 2px;
}
.bsr-po-breakdown {
  display: flex; flex-wrap: wrap; gap: 8px 16px;
  margin-top: 10px; padding-top: 10px;
  border-top: 1px dashed #e5e7eb;
}
.bsr-breakdown-chip { font-size: 12px; color: #6b7280; }
.bsr-breakdown-chip strong { color: #111827; font-weight: 700; }
```

- [ ] **Step 6: Verify the build compiles**

Run: `cd frontend && CI=true npm run build`
Expected: compiles successfully, no errors referencing `bsrLocationAmount`,
`bsrLocationItemCount`, `bsrWarehouseBreakdown`, or `enforcedLocationName`.

- [ ] **Step 7: Manual verification (record result before claiming done)**

Run the app (`npm run demo` for a prod build per project convention). Then:
1. Log in as `warehouse_personnel` at a warehouse that owns *part* of a
   multi-warehouse PO. Open the receiving screen. Expected: the PO card's
   TOTAL AMOUNT and ITEMS reflect **only** that warehouse's lines, with a
   `for {warehouse}` clarifier; no breakdown row.
2. Log in as `admin`. Expected: the same PO card shows the whole-PO TOTAL AMOUNT
   and full item count, plus a per-warehouse breakdown row; a single-warehouse PO
   shows no breakdown row.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/styles/po_receiving.css
git commit -m "feat(po-receiving): scope PO card total/items to receiver's warehouse

Warehouse receivers see only their warehouse's slice of a multi-warehouse PO;
admins keep the whole-PO figure plus a per-warehouse breakdown.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Notes

- Exports (`poExport.js`) and the PO detail modal (`PurchaseOrderPage.jsx`) already
  present per-warehouse totals (one page/sheet per warehouse; per-group subtotal in
  the modal). They are intentionally **not** modified — verified in the spec.
- No backend changes; the list payload already includes per-line `subtotal` and
  `location_id`.
