# View Batch Serial Numbers in a Modal

**Date:** 2026-07-21
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

Batch serial numbers currently render as inline chips in two places on the Inventory
Monitoring page (Console Batches expander + Warehouse Map cards). When a batch has many
serials, the chips overflow and dominate the row/card — with many products listed, the lists
take over the screen.

## Goal

Replace the inline chip lists with a compact per-batch trigger, **"View serials (N)"**, that
opens a modal listing that batch's serials. Batches with no serials keep showing `—`. No
backend change — the endpoints already return `serials` per batch.

## UI

- **Trigger (both views):** when `batch.serials.length > 0`, render a
  `inv-action-link` button labeled `View serials (N)` (N = count); else render `—`.
  `inv-action-link` is the existing link style used by the row's "View"/"Batches" actions,
  so the trigger looks native.
- **Modal:** reuse the existing `Modal` component
  (`frontend/src/admin/components/Modal.jsx`, props `isOpen`, `onClose`, `title`, `maxWidth`;
  its body already scrolls at `max-height: 90vh`). Title:
  `Serial Numbers — <context>`, where context is the PO number (Console) or product name
  (Warehouse Map), falling back to `Batch`. Body: a count line plus the serials rendered with
  the existing `inv-serial-chips` / `inv-serial-chip` styles. Long lists scroll inside the
  modal, so nothing overflows the row or card.

## State & wiring

Add one state hook to `InventoryManagement.jsx`:

```jsx
const [serialModalBatch, setSerialModalBatch] = useState(null);
```

- Console trigger onClick:
  `setSerialModalBatch({ title: b.is_legacy ? 'Pre-tracking stock' : (b.po_number || 'Batch'), serials: b.serials })`
- Warehouse Map trigger onClick:
  `setSerialModalBatch({ title: batch.product_name || batch.po_number || 'Batch', serials: batch.serials })`
- One `Modal` rendered once (near the existing detail modal), open when
  `serialModalBatch` is truthy, cleared to `null` on close.

The trigger stores a shallow `{ title, serials }` snapshot, so the modal is decoupled from
which view opened it — a single modal serves both.

## Component changes

**File:** `frontend/src/admin/pages/InventoryManagement.jsx`
- Add the `serialModalBatch` state.
- Console Batches expander: replace the inline chip `<td>` content with the trigger.
- Warehouse Map card: replace the inline chip block inside `.inv-warehouse-map-serials` with
  the trigger (keep the `Serial Numbers` label and the `—` empty case).
- Add the shared `Modal` block driven by `serialModalBatch`.

**File:** `frontend/src/admin/styles/inventory_management.css`
- Add small styles for the modal body: `.inv-serial-modal` (spacing) and
  `.inv-serial-modal-count` (muted count line). Reuse existing chip styles.

## Out of scope

- No backend/API change (serials already returned per batch).
- No change to how serials are resolved or which serials belong to a batch.
- No search/filter inside the modal (YAGNI; the list is per-batch and scrolls).

## Testing

- **Frontend lint:** `npx eslint src/admin/pages/InventoryManagement.jsx` → 0 errors (the
  pre-existing `warehouses` `no-unused-vars` warning is unrelated).
- **Manual verification:**
  - Console tab → expand a SKU's Batches: a batch with serials shows `View serials (N)`;
    clicking opens the modal listing them; a batch with none shows `—`.
  - Warehouse Map tab: each card's Serial Numbers section shows the trigger or `—`; the modal
    opens with the product-named title.
  - The modal scrolls for a long list; closing (X, overlay click, Esc) clears it.
