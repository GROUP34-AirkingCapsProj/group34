# Clickable Alert Navigation — Design

**Date:** 2026-06-27
**Branch:** backupV3
**Status:** Approved design, ready for implementation planning

## Problem

The Alerts & Notices page (`/admin/alerts`, `AlertsNotices.jsx`) lists three kinds of
alerts — Pending approvals (POs / Purchase Requests / Item Registrations), Low stock
alerts, and the Audit Trail. Today none of the individual items navigate anywhere:
pending and low-stock cards are static, audit rows only expand/collapse. When a user
sees a notification (e.g. a Purchase Order awaiting approval) they have to leave the
page and hunt the record down manually before they can act on it.

## Goal

Make individual alert items clickable so a click takes the user straight to where they
can act — landing on the correct page **and** auto-opening + highlighting the exact
record (its existing detail modal / row), not just the list page.

## Scope

In scope (all three sections become clickable):

- **Pending approvals** — POs, Purchase Requests, Item Registrations.
- **Low stock alerts** — each low-stock row.
- **Audit trail entries** — clickable **only when the record type maps to a destination
  page**. Unmappable types (login/logout, user edits, deleted records, and any table
  without a detail view) keep today's expand/collapse behavior and are not clickable.

Out of scope:

- The header bell still navigates to `/admin/alerts` as it does today (no per-item
  dropdown on the bell).
- No new per-record detail routes (`/purchase-orders/:id`); destination pages remain
  list views that open a record in their existing modal.
- Bell badge counting / realtime push (`project_realtime_push_v2`) is unchanged.

## Approach

Chosen: **URL query param** (`?focus=<type>:<id>`). Rejected alternatives: router
`location.state` (lost on reload, not shareable, same per-page work) and a shared
"focus bus" context (more moving parts for the same result). The query param is
shareable, survives reload, supports browser back, reuses the modals the pages already
have, and keeps the alert→destination mapping centralized in one helper.

## Architecture

Three units, each with one job:

### 1. `alertTarget(item)` — routing map (new util)

`frontend/src/admin/utils/alertTarget.js`. Pure function. Given an alert item (pending
PO/PR/product, low-stock row, or audit entry) returns either `null` (not navigable) or
`{ path, focusKey }` where `focusKey` is `"<type>:<id>"` (e.g. `"po:42"`).

This is the **single source of truth** for "which alert goes where," including the audit
`table_name → page` map. Returning `null` for unmappable audit tables is how the
"clickable only when mappable" rule is enforced. The existing detail-table map
(`purchase_order_details → purchase_orders`, etc.) is reused so a line-item audit row
resolves to its parent record's id.

Mapping table:

| Alert source | Destination path | focusKey |
|---|---|---|
| Pending PO | `/admin/draft-po-creator` (status tab) | `po:<id>` |
| Pending PR | `/admin/purchase-requests` (approvals tab) | `pr:<id>` |
| Pending item registration | _(none — non-clickable)_ | `null` — no approval destination exists in the current UI (`canApprovePendingRegistrations` / `pendingProductsAPI.getAll`/`approve` are defined but unused). Revisit when an approval page exists. |
| Low-stock row | `/admin/inventory` | `inv:<inventory_id>` (fallback `product:<product_id>` if no inventory_id) |
| Audit — `purchase_orders` | `/admin/draft-po-creator` (status tab) | `po:<id>` |
| Audit — `purchase_requests` | `/admin/purchase-requests` (approvals tab) | `pr:<id>` |
| Audit — `transfers` | `/admin/transfers` | `transfer:<id>` |
| Audit — `receivings` | `/admin/receivings` | `receiving:<id>` |
| Audit — `products` | `/admin/items` | `product:<id>` |
| Audit — `inventory` | `/admin/inventory` | `inv:<id>` |
| Audit — `issuances`, `sales`, `adjustments`, … | mapped only if that page has a detail view; else `null` |
| Audit — `users`, login/logout, any `delete` | `null` (not clickable) |

Record-id matching on each target page uses a shared `findById(list, idFields, focusId)`
helper that checks several candidate id fields (e.g. `['po_id','id']`), so a field-name
mismatch degrades safely to the not-found toast rather than throwing.

> During planning, confirm per page whether a detail view exists for `issuances`,
> `sales`, `adjustments` before adding them to the map; ship `null` for any without one.

### 2. `useFocusTarget(type)` — destination-page reader (new hook)

A target page calls `const focusId = useFocusTarget('po')`. The hook reads `?focus=` from
the URL; if the type segment matches, it returns the id (`42`) once, otherwise `null`.
After firing it clears the param via `navigate(..., { replace: true })` so a refresh or
back navigation does not re-pop the modal.

### 3. Clickable alert rows (edit `AlertsNotices.jsx`)

Each row in the three sections computes `alertTarget(item)`. If non-null the row becomes
a button/link — pointer cursor, hover state, keyboard-focusable, Enter/Space activates —
that calls `navigate(path)`. If `null`, the row renders exactly as today (audit rows keep
their expand/collapse).

## Data flow

```
click row
  → alertTarget(item) → { path: '/admin/draft-po-creator', focusKey: 'po:42' }
  → navigate('/admin/draft-po-creator?focus=po:42')
  → target page mounts, loads its list
  → useFocusTarget('po') returns 42
  → page finds PO #42 in loaded list → opens existing modal (setSelectedPO) → scrolls into view → ak-focus-pulse highlight (~2s)
  → param cleared (replace)
```

## Target-page reaction pattern

Same shape on each destination page:

1. Page loads its list as it already does.
2. `useFocusTarget('<type>')` returns the focus id from the URL immediately; the page
   acts on it once its list has finished loading (e.g. in an effect keyed on
   `[focusId, listLoaded]`).
3. Page finds the record in its loaded array → opens the existing modal
   (`setSelectedPO`, etc.) → scrolls into view → applies `ak-focus-pulse` for ~2s.
4. **Not-found handling:** if the id is not in the loaded list (PO already approved,
   record outside the user's location scope, etc.), show a toast — e.g. *"That purchase
   order is no longer pending / not available."* — instead of opening nothing. The param
   is still cleared.

Pages confirmed to already have the needed modal: `PurchaseOrderPage` (`selectedPO` /
`setSelectedPO`, detail modal at lines ~504/1092). Other pages
(`PurchaseRequestPage`, `InventoryManagement`, `ItemManagement`, `ReceivingManagement`,
`StockTransferManagement`) follow the same modal pattern; confirm each during planning.

## Highlight visual

A shared `ak-focus-pulse` class in `airking_ds.css` — a brief 2-pulse ring/background
fade using the role accent (`--role-accent`). One class, reused on every target page.

## Error handling / edge cases

- Unknown or malformed `?focus=` → ignored silently; page behaves normally.
- Permission/scope: navigation never bypasses page auth. A scoped user landing on a
  record they cannot see falls through to the not-found toast.
- Bell badge behavior unchanged — still routes to `/admin/alerts`; only the items inside
  became clickable.
- A click on an unmappable audit row does nothing navigational (it keeps expanding).

## Testing

- **Unit — `alertTarget()`** (riskiest logic): each source type resolves to the expected
  `{ path, focusKey }`; every unmappable case (login/logout, user edits, deletes,
  unmapped tables) returns `null`; line-item detail tables resolve to the parent id.
- **Unit — `useFocusTarget`**: parses a matching type, ignores a mismatched type, returns
  the id once, and clears the param after read.
- **Manual**: click one item per section → correct page opens the right record with the
  pulse; click a stale pending PO → not-found toast; click an unmappable audit row → no
  navigation, still expands.

## Files touched

- New: `frontend/src/admin/utils/alertTarget.js`
- New: `frontend/src/admin/hooks/useFocusTarget.js` (or co-located util)
- New: tests for both of the above
- Edit: `frontend/src/admin/pages/AlertsNotices.jsx` (rows become clickable)
- Edit: target pages to consume `useFocusTarget` + open/highlight + not-found toast
  (`PurchaseOrderPage`, `PurchaseRequestPage`, `InventoryManagement`, `ItemManagement`,
  `ReceivingManagement`, `StockTransferManagement` — only those actually targeted)
- Edit: `frontend/src/admin/styles/airking_ds.css` (`ak-focus-pulse`)
