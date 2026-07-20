# Receiving History Log — Design

## Problem

The Receive PO scan screen (`BarcodeScan.jsx`) only shows an ephemeral, session-scoped scan list (`scanHistory`, backed by `localStorage`). Once the browser tab is closed or the storage is cleared, there is no way for a receiver to look back at what was received previously, on which PO, by whom, or when — other than opening each PO individually. There is no persistent, browsable log of receiving activity accessible from the scan screen itself.

Separately, the PO detail page gained a per-record **History tab** and `RecordHistoryModal` today (`2026-07-20-pr-po-history-and-receiving-receipt-design.md`), which renders a full lifecycle timeline for a single PO, including its receiving milestones. That work is reused here rather than duplicated.

## Goal

Add a **History** tab to the Receive PO scan screen: a persistent, filterable log of receiving activity across all purchase orders, grouped by PO, that opens into the existing `RecordHistoryModal` timeline when a row is clicked.

## Non-goals

- No changes to `RecordHistoryModal` or `RecordHistoryTimeline` — reused as-is.
- No changes to the ephemeral in-session `scanHistory`/`txLogs` UI already in `BarcodeScan.jsx`.
- No new permission gate — the log reuses the same location-scoping as the existing `/receivings` endpoints.
- Not a per-scan (per-`Receiving`-row) log — see "Grouping" below.

## Backend

### New endpoint: `GET /receivings/po-log`

New method `ReceivingController::poLog(Request $request)`, registered near the other `/receivings` routes (same middleware group: `auth:sanctum`, `location.scope`, etc. — no extra permission needed, consistent with the existing `/receivings` index).

**Grouping:** One row per purchase order that has at least one `Receiving` record, not one row per scan. A single PO can accumulate many `Receiving` rows (one per scan, per existing `RCV-{timestamp}` behavior) — the log summarizes them per PO rather than listing each individually, since every row's click-through leads to the same PO-level history view.

**Query shape:**
- Base scope: `Receiving::forUser($request->user())` (same trait/scope used by the existing index) to determine which receiving records — and therefore which POs — are visible to the current user.
- Apply optional filters to that scoped receiving set before grouping:
  - `location_id` — receiving's warehouse.
  - `from_date` / `to_date` — bounds on `receiving_date`.
- Derive the distinct set of `po_id`s from the filtered/scoped receiving records.
- Load `PurchaseOrder` rows for those ids, with:
  - `supplier` relation (for display and `search`).
  - `events_count` — count of matching `Receiving` rows for that PO.
  - `last_received_date` — max `receiving_date` among matching rows.
  - `total_items` — sum of `quantity_amount` across `receiving.details` for matching rows.
  - Distinct warehouse name(s) involved (via matching rows' `location`).
- `search` filter (optional) — matches `po_number` or supplier name, applied to the PO query.
- Sort by `last_received_date` descending.
- Paginate (`per_page`, default 20), using the existing `paginated()` helper for response shape consistency with other list endpoints.

**Response shape (per row):**
```json
{
  "po_id": 123,
  "po_number": "PO-2026-0456",
  "supplier": { "supplier_id": 7, "supplier_name": "Acme Corp" },
  "warehouses": ["Main Warehouse", "Branch B"],
  "events_count": 6,
  "last_received_date": "2026-07-19",
  "total_items": 214
}
```

### Frontend API

Add to `frontend/src/admin/services/api.js`, in the `receivingsAPI` object:
```js
poLog: async (params = {}) => {
  const query = new URLSearchParams(params).toString();
  return apiRequest(`/receivings/po-log${query ? `?${query}` : ''}`);
},
```

## Frontend

### Tab placement

Add a "History" tab alongside the existing scan mode tabs in `BarcodeScan.jsx`'s Receive PO view. Follows the same tab-switching pattern already used for the other modes in that file.

### List view

Table/list of grouped rows, each showing: PO Number, Supplier, Warehouse(s), Events (count), Last Received (date), Total Items.

**Filter bar** above the list:
- Search input (PO number or supplier).
- Warehouse dropdown (populated from the locations already available to the page).
- Date range (from/to).

**Pagination** — reuse whatever pagination control pattern already exists elsewhere in `BarcodeScan.jsx` or a shared admin list pattern, for visual consistency.

**Empty/loading states** — same conventions as the existing `scanHistory` panels in the same file (e.g. `rh-empty`-style message when there's nothing to show, per `[[project_ui_feedback_conventions]]` if a shared empty-state helper exists — otherwise a plain message consistent with the file's existing style).

### Row click → PO History modal

Reuses the exact pattern from `PurchaseOrderPage.jsx`'s `openPoHistory`:
```js
const openPoHistory = (poId) => {
  setHistoryLoading(true);
  Promise.all([
    purchaseOrdersAPI.getById(poId),
    purchaseOrdersAPI.history(poId),
  ])
    .then(([poRes, historyRes]) => {
      setHistoryRecord(poRes?.data || null);
      setHistoryMilestones(Array.isArray(historyRes?.data) ? historyRes.data : []);
    })
    .catch(() => { setHistoryRecord(null); setHistoryMilestones([]); })
    .finally(() => setHistoryLoading(false));
};
```
`purchaseOrdersAPI.getById(poId)` is used (rather than the summary row already in hand) to get the full PO — including `details`, `status`, `supplier` — matching what `RecordHistoryModal` expects for its Items section and header.

`BarcodeScan.jsx` imports `RecordHistoryModal` and renders it the same way `PurchaseOrderPage.jsx` does:
```jsx
<RecordHistoryModal
  isOpen={!!historyRecord}
  onClose={() => setHistoryRecord(null)}
  kind="Purchase Order"
  record={historyRecord}
  statusLabel={historyRecord ? getStatusConfig(historyRecord.status?.status_name).label : ''}
  statusColor={historyRecord ? getStatusConfig(historyRecord.status?.status_name).color : 'draft'}
  milestones={historyMilestones}
  loading={historyLoading}
/>
```
`getStatusConfig` is currently defined locally inside `PurchaseOrderPage.jsx` (not shared) and `BarcodeScan.jsx` has no equivalent today. Rather than duplicating the status→label/color map, extract it to a small shared helper (e.g. `frontend/src/admin/utils/poStatus.js`) and import it from both `PurchaseOrderPage.jsx` and `BarcodeScan.jsx`.

## Testing

Backend feature test, e.g. `back-end/tests/Feature/History/ReceivingPoLogTest.php`:
- Groups multiple `Receiving` rows for the same PO into a single log entry with the correct `events_count` and `total_items`.
- `location_id` filter narrows to receivings at that location (and therefore excludes POs with no matching-location receivings).
- `from_date`/`to_date` filter narrows by `receiving_date`.
- `search` matches PO number and supplier name.
- A user scoped to one location does not see POs whose only receiving activity is at a different location (mirrors existing `forUser` scoping tests on the `/receivings` index, if any exist).

No new frontend test infra — consistent with how the History tab / `RecordHistoryModal` work landed today without new frontend tests.
