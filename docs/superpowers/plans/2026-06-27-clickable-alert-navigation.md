# Clickable Alert Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make individual items on the Alerts & Notices page clickable so a click navigates to the right page and auto-opens + highlights the exact record.

**Architecture:** A pure `alertTarget()` helper maps each alert item to `{ path, focusKey }` (the single source of truth for routing). Alert rows link to `path?focus=<type>:<id>`. Each destination page reads the param via `useFocusTarget(type)`, finds the record in its already-loaded list with `findById()`, opens its existing detail modal, and pulses it with `useFocusPulse()`. Unmappable items return `null` and stay non-clickable.

**Tech Stack:** React 18 (CRA), react-router-dom v6 (`useLocation`/`useNavigate`/`useSearchParams`), Jest + @testing-library/react, SweetAlert2 toast helper (`src/admin/utils/toast.js`).

**Spec:** `docs/superpowers/specs/2026-06-27-clickable-alert-navigation-design.md`

**Known exclusion:** Pending **item registrations** have no wired approval destination in the current UI (`canApprovePendingRegistrations` / `pendingProductsAPI.getAll`/`approve` are defined but unused). `alertTarget` returns `null` for them — they stay non-clickable until an approval page exists. Wiring that page is out of scope for this plan.

**Audit scope:** Audit rows are clickable only for tables with a real detail view: `purchase_orders`, `purchase_requests`, `transfers`, `receivings`, `products`, `inventory`. All other tables, and any `delete` action, return `null`.

---

## File Structure

- Create: `frontend/src/admin/utils/alertTarget.js` — pure alert→`{path,focusKey}` mapping.
- Create: `frontend/src/admin/utils/alertTarget.test.js` — unit tests.
- Create: `frontend/src/admin/utils/focusParam.js` — pure `parseFocus(search, type)`.
- Create: `frontend/src/admin/utils/focusParam.test.js` — unit tests.
- Create: `frontend/src/admin/utils/findById.js` — pure list lookup by candidate id fields.
- Create: `frontend/src/admin/utils/findById.test.js` — unit tests.
- Create: `frontend/src/admin/hooks/useFocusTarget.js` — reads `?focus=`, returns id once, clears param.
- Create: `frontend/src/admin/hooks/useFocusTarget.test.js` — render test with MemoryRouter.
- Create: `frontend/src/admin/hooks/useFocusPulse.js` — `[pulsing, firePulse]` timed flag.
- Modify: `frontend/src/admin/styles/airking_ds.css` — add `.ak-focus-pulse`.
- Modify: `frontend/src/admin/pages/AlertsNotices.jsx` — clickable rows + audit "Open record" link.
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` — consume `po` focus.
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` — consume `pr` focus.
- Modify: `frontend/src/admin/pages/ReceivingManagement.jsx` — consume `receiving` focus.
- Modify: `frontend/src/admin/pages/StockTransferManagement.jsx` — consume `transfer` focus.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — consume `inv` focus.
- Modify: `frontend/src/admin/pages/ItemManagement.jsx` — consume `product` focus.

**Test runner:** `cd frontend && npx react-scripts test --watchAll=false <path>` (CRA Jest). Set `CI=true` to avoid watch mode.

---

## Task 1: `findById` lookup helper

**Files:**
- Create: `frontend/src/admin/utils/findById.js`
- Test: `frontend/src/admin/utils/findById.test.js`

- [ ] **Step 1: Write the failing test**

```js
// frontend/src/admin/utils/findById.test.js
import { findById } from './findById';

describe('findById', () => {
  const list = [
    { po_id: 10, name: 'A' },
    { po_id: 11, id: 99, name: 'B' },
  ];

  it('matches on the first candidate field (loose string compare)', () => {
    expect(findById(list, ['po_id', 'id'], '10')).toEqual({ po_id: 10, name: 'A' });
    expect(findById(list, ['po_id', 'id'], 11)).toEqual({ po_id: 11, id: 99, name: 'B' });
  });

  it('falls through to a later candidate field', () => {
    expect(findById(list, ['id'], 99)).toEqual({ po_id: 11, id: 99, name: 'B' });
  });

  it('returns null when not found or inputs are bad', () => {
    expect(findById(list, ['po_id'], 404)).toBeNull();
    expect(findById(null, ['po_id'], 10)).toBeNull();
    expect(findById(list, ['po_id'], null)).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/findById.test.js`
Expected: FAIL — "Cannot find module './findById'".

- [ ] **Step 3: Write minimal implementation**

```js
// frontend/src/admin/utils/findById.js
// Find an item in `list` by matching any of `idFields` against `focusId`
// using a loose string comparison. Returns the item or null.
export function findById(list, idFields, focusId) {
  if (!Array.isArray(list) || focusId == null) return null;
  return (
    list.find((row) =>
      idFields.some((f) => row?.[f] != null && String(row[f]) === String(focusId))
    ) || null
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/findById.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/findById.js frontend/src/admin/utils/findById.test.js
git commit -m "feat(alerts): add findById list lookup helper"
```

---

## Task 2: `parseFocus` query-param parser

**Files:**
- Create: `frontend/src/admin/utils/focusParam.js`
- Test: `frontend/src/admin/utils/focusParam.test.js`

- [ ] **Step 1: Write the failing test**

```js
// frontend/src/admin/utils/focusParam.test.js
import { parseFocus } from './focusParam';

describe('parseFocus', () => {
  it('returns the id when type matches', () => {
    expect(parseFocus('?focus=po:42', 'po')).toBe('42');
    expect(parseFocus('focus=inv:7&tab=x', 'inv')).toBe('7');
  });

  it('returns null when the type does not match', () => {
    expect(parseFocus('?focus=po:42', 'pr')).toBeNull();
  });

  it('returns null for missing/malformed focus', () => {
    expect(parseFocus('', 'po')).toBeNull();
    expect(parseFocus('?tab=status', 'po')).toBeNull();
    expect(parseFocus('?focus=po', 'po')).toBeNull();
    expect(parseFocus('?focus=po:', 'po')).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/focusParam.test.js`
Expected: FAIL — "Cannot find module './focusParam'".

- [ ] **Step 3: Write minimal implementation**

```js
// frontend/src/admin/utils/focusParam.js
// Parse a `?focus=<type>:<id>` query string. Returns the id (string) when the
// type segment matches the requested `type`, otherwise null.
export function parseFocus(search, type) {
  const raw = new URLSearchParams(search).get('focus');
  if (!raw) return null;
  const idx = raw.indexOf(':');
  if (idx === -1) return null;
  const t = raw.slice(0, idx);
  const id = raw.slice(idx + 1);
  return t === type && id ? id : null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/focusParam.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/focusParam.js frontend/src/admin/utils/focusParam.test.js
git commit -m "feat(alerts): add parseFocus query-param parser"
```

---

## Task 3: `alertTarget` routing map

**Files:**
- Create: `frontend/src/admin/utils/alertTarget.js`
- Test: `frontend/src/admin/utils/alertTarget.test.js`

- [ ] **Step 1: Write the failing test**

```js
// frontend/src/admin/utils/alertTarget.test.js
import {
  pendingPoTarget,
  pendingPrTarget,
  pendingRegistrationTarget,
  lowStockTarget,
  auditTarget,
  targetHref,
} from './alertTarget';

describe('alertTarget', () => {
  it('maps a pending PO to the status tab', () => {
    expect(pendingPoTarget({ id: 42 })).toEqual({
      path: '/admin/draft-po-creator', focusKey: 'po:42',
    });
    expect(pendingPoTarget({ po_id: 7 })).toEqual({
      path: '/admin/draft-po-creator', focusKey: 'po:7',
    });
    expect(pendingPoTarget({})).toBeNull();
  });

  it('maps a pending PR to the approvals page', () => {
    expect(pendingPrTarget({ id: 5 })).toEqual({
      path: '/admin/purchase-requests', focusKey: 'pr:5',
    });
  });

  it('returns null for pending registrations (no destination yet)', () => {
    expect(pendingRegistrationTarget({ id: 3 })).toBeNull();
  });

  it('maps low stock to inventory by inventory_id, falling back to product', () => {
    expect(lowStockTarget({ inventory_id: 8, product_id: 2 })).toEqual({
      path: '/admin/inventory', focusKey: 'inv:8',
    });
    expect(lowStockTarget({ product_id: 2 })).toEqual({
      path: '/admin/items', focusKey: 'product:2',
    });
    expect(lowStockTarget({})).toBeNull();
  });

  it('maps audit entries only for mappable tables', () => {
    expect(auditTarget({ table_name: 'purchase_orders', record_id: 12, action: 'update' }))
      .toEqual({ path: '/admin/draft-po-creator', focusKey: 'po:12' });
    expect(auditTarget({ table_name: 'transfers', record_id: 4, action: 'create' }))
      .toEqual({ path: '/admin/transfers', focusKey: 'transfer:4' });
    expect(auditTarget({ table_name: 'products', record_id: 9, action: 'update' }))
      .toEqual({ path: '/admin/items', focusKey: 'product:9' });
    expect(auditTarget({ table_name: 'inventory', record_id: 3, action: 'update' }))
      .toEqual({ path: '/admin/inventory', focusKey: 'inv:3' });
  });

  it('returns null for unmappable audit entries', () => {
    expect(auditTarget({ table_name: 'users', record_id: 1, action: 'update' })).toBeNull();
    expect(auditTarget({ table_name: 'sales', record_id: 1, action: 'create' })).toBeNull();
    expect(auditTarget({ table_name: 'purchase_orders', record_id: 1, action: 'delete' })).toBeNull();
    expect(auditTarget({ table_name: 'purchase_orders', action: 'update' })).toBeNull();
    expect(auditTarget(null)).toBeNull();
  });

  it('builds an href from a target', () => {
    expect(targetHref({ path: '/admin/inventory', focusKey: 'inv:8' }))
      .toBe('/admin/inventory?focus=inv%3A8');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/alertTarget.test.js`
Expected: FAIL — "Cannot find module './alertTarget'".

- [ ] **Step 3: Write minimal implementation**

```js
// frontend/src/admin/utils/alertTarget.js
// Single source of truth: maps an alert item to a navigation target
// { path, focusKey } where focusKey is "<type>:<id>", or null when not navigable.

const AUDIT_TABLE_TARGETS = {
  purchase_orders:   (id) => ({ path: '/admin/draft-po-creator', focusKey: `po:${id}` }),
  purchase_requests: (id) => ({ path: '/admin/purchase-requests', focusKey: `pr:${id}` }),
  transfers:         (id) => ({ path: '/admin/transfers',         focusKey: `transfer:${id}` }),
  receivings:        (id) => ({ path: '/admin/receivings',        focusKey: `receiving:${id}` }),
  products:          (id) => ({ path: '/admin/items',             focusKey: `product:${id}` }),
  inventory:         (id) => ({ path: '/admin/inventory',         focusKey: `inv:${id}` }),
};

export function pendingPoTarget(po) {
  const id = po?.id ?? po?.po_id;
  return id != null ? { path: '/admin/draft-po-creator', focusKey: `po:${id}` } : null;
}

export function pendingPrTarget(pr) {
  const id = pr?.id ?? pr?.pr_id;
  return id != null ? { path: '/admin/purchase-requests', focusKey: `pr:${id}` } : null;
}

// No wired approval destination yet — intentionally non-clickable.
export function pendingRegistrationTarget() {
  return null;
}

export function lowStockTarget(item) {
  const invId = item?.inventory_id;
  if (invId != null) return { path: '/admin/inventory', focusKey: `inv:${invId}` };
  const pid = item?.product_id;
  return pid != null ? { path: '/admin/items', focusKey: `product:${pid}` } : null;
}

export function auditTarget(entry) {
  if (!entry || entry.action === 'delete') return null;
  const builder = AUDIT_TABLE_TARGETS[entry.table_name];
  const id = entry.record_id;
  return builder && id != null ? builder(id) : null;
}

export function targetHref(target) {
  return target ? `${target.path}?focus=${encodeURIComponent(target.focusKey)}` : null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/utils/alertTarget.test.js`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/alertTarget.js frontend/src/admin/utils/alertTarget.test.js
git commit -m "feat(alerts): add alertTarget routing map"
```

---

## Task 4: `useFocusTarget` hook

**Files:**
- Create: `frontend/src/admin/hooks/useFocusTarget.js`
- Test: `frontend/src/admin/hooks/useFocusTarget.test.js`

- [ ] **Step 1: Write the failing test**

```js
// frontend/src/admin/hooks/useFocusTarget.test.js
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { useFocusTarget } from './useFocusTarget';

function Probe() {
  const focusId = useFocusTarget('po');
  const loc = useLocation();
  return (
    <div>
      <span data-testid="focus">{focusId ?? 'none'}</span>
      <span data-testid="search">{loc.search || 'empty'}</span>
    </div>
  );
}

describe('useFocusTarget', () => {
  it('returns the id for a matching type and clears the param', async () => {
    render(
      <MemoryRouter initialEntries={['/admin/draft-po-creator?focus=po:42']}>
        <Probe />
      </MemoryRouter>
    );
    expect(screen.getByTestId('focus').textContent).toBe('42');
    await waitFor(() =>
      expect(screen.getByTestId('search').textContent).toBe('empty')
    );
  });

  it('returns none for a mismatched type', () => {
    render(
      <MemoryRouter initialEntries={['/admin/draft-po-creator?focus=pr:42']}>
        <Probe />
      </MemoryRouter>
    );
    expect(screen.getByTestId('focus').textContent).toBe('none');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/hooks/useFocusTarget.test.js`
Expected: FAIL — "Cannot find module './useFocusTarget'".

- [ ] **Step 3: Write minimal implementation**

```js
// frontend/src/admin/hooks/useFocusTarget.js
import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { parseFocus } from '../utils/focusParam';

// Reads ?focus=<type>:<id> for the given type. Returns the id (string) once,
// then strips the param (replace) so reload/back-navigation does not re-fire.
export function useFocusTarget(type) {
  const location = useLocation();
  const navigate = useNavigate();
  const [focusId, setFocusId] = useState(() => parseFocus(location.search, type));

  useEffect(() => {
    const id = parseFocus(location.search, type);
    if (!id) return;
    setFocusId(id);
    const params = new URLSearchParams(location.search);
    params.delete('focus');
    const search = params.toString();
    navigate(
      { pathname: location.pathname, search: search ? `?${search}` : '' },
      { replace: true }
    );
  }, [location.search, location.pathname, type, navigate]);

  return focusId;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false src/admin/hooks/useFocusTarget.test.js`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/hooks/useFocusTarget.js frontend/src/admin/hooks/useFocusTarget.test.js
git commit -m "feat(alerts): add useFocusTarget hook"
```

---

## Task 5: `useFocusPulse` hook + `.ak-focus-pulse` CSS

**Files:**
- Create: `frontend/src/admin/hooks/useFocusPulse.js`
- Modify: `frontend/src/admin/styles/airking_ds.css` (append)

No test (pure timing/CSS — verified manually in Task 7+).

- [ ] **Step 1: Create the hook**

```js
// frontend/src/admin/hooks/useFocusPulse.js
import { useCallback, useEffect, useRef, useState } from 'react';

// Returns [pulsing, firePulse]. Calling firePulse() sets pulsing=true for
// durationMs, then back to false. Used to flash a freshly focused modal.
export function useFocusPulse(durationMs = 2000) {
  const [pulsing, setPulsing] = useState(false);
  const timer = useRef(null);

  const firePulse = useCallback(() => {
    setPulsing(true);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setPulsing(false), durationMs);
  }, [durationMs]);

  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

  return [pulsing, firePulse];
}
```

- [ ] **Step 2: Append the CSS to `airking_ds.css`**

Add at the end of `frontend/src/admin/styles/airking_ds.css`:

```css
/* Focus pulse — flashes a record opened via an alert deep-link (?focus=). */
@keyframes ak-focus-pulse-kf {
  0%   { box-shadow: 0 0 0 0 var(--role-accent, #2563eb); }
  35%  { box-shadow: 0 0 0 6px color-mix(in srgb, var(--role-accent, #2563eb) 35%, transparent); }
  100% { box-shadow: 0 0 0 0 transparent; }
}
.ak-focus-pulse {
  animation: ak-focus-pulse-kf 1s ease-out 2;
  border-radius: 8px;
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/hooks/useFocusPulse.js frontend/src/admin/styles/airking_ds.css
git commit -m "feat(alerts): add useFocusPulse hook and ak-focus-pulse style"
```

---

## Task 6: Make Alerts & Notices rows clickable

**Files:**
- Modify: `frontend/src/admin/pages/AlertsNotices.jsx`

Pending PO/PR rows become clickable; low-stock rows become clickable; mappable
audit entries gain an "Open record →" link (the existing header keeps its
expand/collapse). Pending registration rows are left unchanged (target is null).

- [ ] **Step 1: Import the helpers**

At the top of `AlertsNotices.jsx`, add to the imports (after line 11, `import DateInput...`):

```js
import { pendingPoTarget, pendingPrTarget, lowStockTarget, auditTarget, targetHref } from '../utils/alertTarget';
```

- [ ] **Step 2: Add a click-props helper inside the component**

Inside `AlertsNotices` (after `const navigate = useNavigate();`, line ~356), add:

```js
  // Turns a non-null alert target into spreadable props that navigate on
  // click/Enter/Space. Returns {} when the item is not navigable.
  const clickProps = useCallback((target) => {
    if (!target) return {};
    const go = () => navigate(targetHref(target));
    return {
      role: 'button',
      tabIndex: 0,
      onClick: go,
      onKeyDown: (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      },
    };
  }, [navigate]);
```

- [ ] **Step 3: Make pending PO rows clickable**

Replace the pending PO row opening tag (line ~577):

```jsx
                      <div className="an-approval-item an-approval-item--urgent" key={`po-${po.id}`}>
```

with:

```jsx
                      <div
                        className="an-approval-item an-approval-item--urgent an-clickable"
                        key={`po-${po.id}`}
                        {...clickProps(pendingPoTarget(po))}
                      >
```

- [ ] **Step 4: Make pending PR rows clickable**

Replace the pending PR row opening tag (line ~602):

```jsx
                      <div className="an-approval-item an-approval-item--urgent" key={`pr-${pr.id}`}>
```

with:

```jsx
                      <div
                        className="an-approval-item an-approval-item--urgent an-clickable"
                        key={`pr-${pr.id}`}
                        {...clickProps(pendingPrTarget(pr))}
                      >
```

(Leave the pending **product registration** rows, line ~627, unchanged.)

- [ ] **Step 5: Make low-stock rows clickable**

Replace the low-stock row opening tag (line ~659):

```jsx
                <div className="an-approval-item" key={item.product_id || item.inventory_id || idx}>
```

with:

```jsx
                <div
                  className="an-approval-item an-clickable"
                  key={item.product_id || item.inventory_id || idx}
                  {...clickProps(lowStockTarget(item))}
                >
```

- [ ] **Step 6: Add an "Open record" link to mappable audit entries**

In `SingleAuditEntry` and `GroupedAuditEntry`, add an `onOpen` prop and render a
link in the meta row. In `SingleAuditEntry`, change the signature (line ~160):

```jsx
const SingleAuditEntry = ({ item, isUnread, isExpanded, onToggle, onOpen }) => {
```

and inside the meta row, after the closing `</span>` of `an-audit-time` block but
before `{item?.ip_address && (...)}` (around line 218), insert:

```jsx
          {onOpen && (
            <>
              <span className="an-audit-sep">·</span>
              <button
                type="button"
                className="an-audit-open-link"
                onClick={(e) => { e.stopPropagation(); onOpen(); }}
              >
                Open record →
              </button>
            </>
          )}
```

Apply the identical signature change and meta-row insert to `GroupedAuditEntry`
(signature line ~269; meta row around line 316, before the `{primary?.ip_address ...}`).

- [ ] **Step 7: Pass `onOpen` from the audit map**

In the audit `.map` (around line 746), compute the target and pass `onOpen`:

```jsx
              auditVisible.map((group, idx) => {
                const gId = group.primary?.audit_id ?? group.primary?.id ?? idx;
                const expanded = expandedIds.has(gId);
                const unread = isUnread(group.primary?.created_at);
                const target = auditTarget(group.primary);
                const onOpen = target ? () => navigate(targetHref(target)) : null;
                return group.type === 'grouped' ? (
                  <GroupedAuditEntry
                    key={gId}
                    primary={group.primary}
                    details={group.details}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
                    onOpen={onOpen}
                  />
                ) : (
                  <SingleAuditEntry
                    key={gId}
                    item={group.primary}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
                    onOpen={onOpen}
                  />
                );
              })
```

- [ ] **Step 8: Add hover/focus styling**

Append to `frontend/src/admin/styles/alerts_notices.css`:

```css
.an-clickable { cursor: pointer; transition: background .12s ease, box-shadow .12s ease; }
.an-clickable:hover { background: var(--ak-hover, rgba(37,99,235,.06)); }
.an-clickable:focus-visible { outline: 2px solid var(--role-accent, #2563eb); outline-offset: 2px; }
.an-audit-open-link {
  background: none; border: none; padding: 0; cursor: pointer;
  color: var(--role-accent, #2563eb); font: inherit; font-weight: 600;
}
.an-audit-open-link:hover { text-decoration: underline; }
```

- [ ] **Step 9: Verify the page renders and lint passes**

Run: `cd frontend && npx eslint src/admin/pages/AlertsNotices.jsx`
Expected: no errors.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/admin/pages/AlertsNotices.jsx frontend/src/admin/styles/alerts_notices.css
git commit -m "feat(alerts): make alert rows clickable to navigate"
```

---

## Task 7: PurchaseOrderPage consumes `po` focus

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx`

The pending-PO modal lives in the **status** tab and its list (`poStatusList`,
loading flag `loadingPOStatus`) only loads when `activeTab === 'status'`. So the
focus handler must switch tabs first, then open once loaded.

- [ ] **Step 1: Add imports and hooks**

Add to imports near the existing util imports:

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

Inside the component, near the other `useState`/`useRef` declarations (after the
PO Status tab state around line 156), add:

```js
  const focusPoId = useFocusTarget('po');
  const focusHandledRef = useRef(false);
  const [poPulse, firePoPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effects**

After the existing "Load PO status list" effect (after line ~206/its block), add:

```js
  // Deep-link: a ?focus=po:<id> arrival switches to the status tab…
  useEffect(() => {
    if (focusPoId && !focusHandledRef.current) setActiveTab('status');
  }, [focusPoId]);

  // …then, once the status list has loaded, opens that PO (or toasts if gone).
  useEffect(() => {
    if (!focusPoId || focusHandledRef.current) return;
    if (activeTab !== 'status' || loadingPOStatus) return;
    focusHandledRef.current = true;
    const po = findById(poStatusList, ['po_id', 'id'], focusPoId);
    if (po) { setSelectedPO(po); firePoPulse(); }
    else toast.error('That purchase order is no longer available.');
  }, [focusPoId, activeTab, loadingPOStatus, poStatusList, firePoPulse]);
```

> If `toast` is not already imported in this file, add `import { toast } from '../utils/toast';`.

- [ ] **Step 3: Apply the pulse class to the PO detail modal**

The PO detail modal content begins inside `<Modal isOpen={!!selectedPO} ...>` (line
~1092). On the first child wrapper element inside that Modal (the detail container),
add the pulse class. For example if it is `<div className="po-detail-card">`, change to:

```jsx
<div className={`po-detail-card${poPulse ? ' ak-focus-pulse' : ''}`}>
```

(Use whatever the actual first wrapper class is; the goal is the visible card pulses.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/PurchaseOrderPage.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

Start the app (`cd frontend && npm run dev`). Log in, go to Alerts, click a pending
PO. Expected: lands on `/admin/draft-po-creator`, status tab active, PO modal open
and pulsing. Click an already-approved PO link (or hand-edit the URL to a stale id):
expect the "no longer available" toast.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(alerts): open focused PO from alert deep-link"
```

---

## Task 8: PurchaseRequestPage consumes `pr` focus

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

PR detail modal opens via `setSelectedPR`. Candidate lists: `pendingPRs`
(loading `loadingPending`) and `myPRs` (loading `loadingMyPRs`). The page already
defaults to the `approvals` tab for approvers.

- [ ] **Step 1: Add imports and hooks**

Add imports:

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

Inside the component (after the PR state declarations, around line 155), add:

```js
  const focusPrId = useFocusTarget('pr');
  const focusHandledRef = useRef(false);
  const [prPulse, firePrPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effect**

After the existing PR-loading effects, add:

```js
  useEffect(() => {
    if (!focusPrId || focusHandledRef.current) return;
    if (loadingPending || loadingMyPRs) return; // wait for both lists
    focusHandledRef.current = true;
    const pr =
      findById(pendingPRs, ['pr_id', 'id'], focusPrId) ||
      findById(myPRs, ['pr_id', 'id'], focusPrId);
    if (pr) { setSelectedPR(pr); firePrPulse(); }
    else toast.error('That purchase request is not available.');
  }, [focusPrId, loadingPending, loadingMyPRs, pendingPRs, myPRs, firePrPulse]);
```

> Ensure `toast` is imported (`import { toast } from '../utils/toast';`).

- [ ] **Step 3: Apply the pulse class to the PR detail modal**

Inside `<Modal isOpen={!!selectedPR} ...>` (line ~1058), add the pulse class to the
first content wrapper element, e.g.:

```jsx
<div className={`pr-detail-card${prPulse ? ' ak-focus-pulse' : ''}`}>
```

(Match the actual wrapper class used in this modal.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/PurchaseRequestPage.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

From Alerts, click a pending PR. Expected: PR detail modal opens and pulses on the
approvals tab.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(alerts): open focused PR from alert deep-link"
```

---

## Task 9: ReceivingManagement consumes `receiving` focus

**Files:**
- Modify: `frontend/src/admin/pages/ReceivingManagement.jsx`

List `receivings` (loading flag `loading`); modal opens via `setViewingReceiving`.

- [ ] **Step 1: Add imports and hooks**

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

After the `viewingReceiving` state (line ~45), add:

```js
  const focusReceivingId = useFocusTarget('receiving');
  const focusHandledRef = useRef(false);
  const [recPulse, fireRecPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effect**

After the receivings-loading effect, add:

```js
  useEffect(() => {
    if (!focusReceivingId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const rec = findById(receivings, ['receiving_id', 'id'], focusReceivingId);
    if (rec) { setViewingReceiving(rec); fireRecPulse(); }
    else toast.error('That receiving record is not available.');
  }, [focusReceivingId, loading, receivings, fireRecPulse]);
```

> Ensure `toast` and `useRef`/`useEffect` are imported.

- [ ] **Step 3: Apply the pulse class to the receiving modal**

The modal is `<Modal title={...} onClose={...}>` (line ~292). Wrap its body's first
element with the pulse class, e.g. on the existing content `<div>`:

```jsx
<div className={`txn-detail-body${recPulse ? ' ak-focus-pulse' : ''}`}>
```

(Match the actual wrapper class.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/ReceivingManagement.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

From Alerts, click a `receivings` audit entry's "Open record →". Expected: the
receiving modal opens and pulses.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/ReceivingManagement.jsx
git commit -m "feat(alerts): open focused receiving from alert deep-link"
```

---

## Task 10: StockTransferManagement consumes `transfer` focus

**Files:**
- Modify: `frontend/src/admin/pages/StockTransferManagement.jsx`

List `transfers` (loading flag `loading`); modal opens via `setViewingTransfer`.

- [ ] **Step 1: Add imports and hooks**

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

After the `viewingTransfer` state (line ~47), add:

```js
  const focusTransferId = useFocusTarget('transfer');
  const focusHandledRef = useRef(false);
  const [trPulse, fireTrPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effect**

After the transfers-loading effect, add:

```js
  useEffect(() => {
    if (!focusTransferId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const tr = findById(transfers, ['transfer_id', 'id'], focusTransferId);
    if (tr) { setViewingTransfer(tr); fireTrPulse(); }
    else toast.error('That transfer is not available.');
  }, [focusTransferId, loading, transfers, fireTrPulse]);
```

> Ensure `toast`, `useRef`, `useEffect` are imported.

- [ ] **Step 3: Apply the pulse class to the transfer modal**

The modal is `<Modal title={...} onClose={...}>` (line ~417). Add the pulse class to
its body's first wrapper element, e.g.:

```jsx
<div className={`txn-detail-body${trPulse ? ' ak-focus-pulse' : ''}`}>
```

(Match the actual wrapper class.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/StockTransferManagement.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

From Alerts, click a `transfers` audit entry's "Open record →". Expected: the
transfer modal opens and pulses.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/StockTransferManagement.jsx
git commit -m "feat(alerts): open focused transfer from alert deep-link"
```

---

## Task 11: InventoryManagement consumes `inv` focus

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`

List `inventory` (loading flag `loading`); modal opens via `setViewingItem` (which
also resets `historyData`/`detailTab` and loads history). Low-stock and `inventory`
audit links both use `inv:<inventory_id>`.

- [ ] **Step 1: Add imports and hooks**

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

After the `viewingItem`/`detailTab` state (around line 109), add:

```js
  const focusInvId = useFocusTarget('inv');
  const focusHandledRef = useRef(false);
  const [invPulse, fireInvPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effect**

After the inventory-loading effect, add (mirror the existing open handler at line
697 which calls `setViewingItem(item); setHistoryData(null); setDetailTab('overview'); loadInventoryHistory(item.inventory_id);`):

```js
  useEffect(() => {
    if (!focusInvId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const item = findById(inventory, ['inventory_id', 'id'], focusInvId);
    if (item) {
      setViewingItem(item);
      setHistoryData(null);
      setDetailTab('overview');
      loadInventoryHistory(item.inventory_id);
      fireInvPulse();
    } else {
      toast.error('That inventory item is not available.');
    }
  }, [focusInvId, loading, inventory, fireInvPulse]);
```

> Ensure `toast`, `useRef`, `useEffect` are imported. If `loadInventoryHistory` is
> defined with `useCallback` below this effect, either move the effect after it or
> add it to the dependency array — match the file's existing ordering to avoid a
> use-before-define lint error.

- [ ] **Step 3: Apply the pulse class to the inventory detail modal**

The modal opens around line 885 (`onClose` resets viewingItem). Add the pulse class
to its first content wrapper element, e.g.:

```jsx
<div className={`inv-modal-body${invPulse ? ' ak-focus-pulse' : ''}`}>
```

(Match the actual wrapper class.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

From Alerts, click a low-stock row. Expected: lands on `/admin/inventory`, the item
detail modal opens and pulses. Also test an `inventory` audit entry "Open record →".

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(alerts): open focused inventory item from alert deep-link"
```

---

## Task 12: ItemManagement consumes `product` focus

**Files:**
- Modify: `frontend/src/admin/pages/ItemManagement.jsx`

List `items` (loading flag `loading`); modal opens via `setViewingItem`. Used by
`products` audit entries and the low-stock product fallback.

- [ ] **Step 1: Add imports and hooks**

```js
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

After the `viewingItem` state (line ~28), add:

```js
  const focusProductId = useFocusTarget('product');
  const focusHandledRef = useRef(false);
  const [itemPulse, fireItemPulse] = useFocusPulse();
```

- [ ] **Step 2: Add the focus effect**

After the items-loading effect, add:

```js
  useEffect(() => {
    if (!focusProductId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const item = findById(items, ['product_id', 'id'], focusProductId);
    if (item) { setViewingItem(item); fireItemPulse(); }
    else toast.error('That product is not available.');
  }, [focusProductId, loading, items, fireItemPulse]);
```

> Ensure `toast`, `useRef`, `useEffect` are imported.

- [ ] **Step 3: Apply the pulse class to the product detail modal**

The modal is `<Modal isOpen={!!viewingItem} ... title="Product Details">` (line ~812).
Add the pulse class to its first content wrapper element, e.g.:

```jsx
<div className={`im-detail-body${itemPulse ? ' ak-focus-pulse' : ''}`}>
```

(Match the actual wrapper class.)

- [ ] **Step 4: Verify lint**

Run: `cd frontend && npx eslint src/admin/pages/ItemManagement.jsx`
Expected: no errors.

- [ ] **Step 5: Manual check**

From Alerts, click a `products` audit entry's "Open record →". Expected: the product
modal opens and pulses.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/ItemManagement.jsx
git commit -m "feat(alerts): open focused product from alert deep-link"
```

---

## Task 13: Full test + lint sweep

- [ ] **Step 1: Run the new unit tests together**

Run: `cd frontend && CI=true npx react-scripts test --watchAll=false src/admin/utils/findById.test.js src/admin/utils/focusParam.test.js src/admin/utils/alertTarget.test.js src/admin/hooks/useFocusTarget.test.js`
Expected: all suites PASS.

- [ ] **Step 2: Lint all touched files**

Run: `cd frontend && npx eslint src/admin/pages/AlertsNotices.jsx src/admin/pages/PurchaseOrderPage.jsx src/admin/pages/PurchaseRequestPage.jsx src/admin/pages/ReceivingManagement.jsx src/admin/pages/StockTransferManagement.jsx src/admin/pages/InventoryManagement.jsx src/admin/pages/ItemManagement.jsx src/admin/utils/alertTarget.js src/admin/hooks/useFocusTarget.js`
Expected: no errors.

- [ ] **Step 3: Manual end-to-end pass**

With `npm run dev` running, click one item in each of the three Alerts sections and
confirm: correct page, correct record opened, pulse visible, and that a stale/invalid
`?focus=` shows the not-found toast instead of a blank modal.

- [ ] **Step 4: Final commit (if any cleanup)**

```bash
git add -A
git commit -m "test(alerts): verify clickable alert navigation end-to-end"
```
