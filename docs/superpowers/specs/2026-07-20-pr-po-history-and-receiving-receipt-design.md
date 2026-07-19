# PR/PO History Tabs & PO Receiving Receipt — Design

**Date:** 2026-07-20
**Branch:** backupV3
**Status:** Approved for planning

## Motivation

Adviser feedback: each procurement document should carry its own **history**, and
a physical **receipt** should be produceable for goods received against a PO.

Concretely:

1. The **Purchase Request** page and the **Purchase Order** page should each gain a
   **History tab** that shows, per record, a readable lifecycle timeline
   (created → verified → approved → received → completed) with the ability to expand
   any milestone to see the underlying field changes.
2. **PO Receiving** should produce a **Goods Received Note (GRN)** — an on-screen
   printable receipt plus a downloadable file (PDF, and CSV).

## What already exists (reused, not rebuilt)

- **`Auditable` trait** (`app/Traits/Auditable.php`) already writes every
  create/update/delete of `purchase_orders`, `purchase_requests`, `receivings`
  (and their detail tables) into the `audit_trail` table, with resolved
  old→new values, actor `user_id`, ip, and timestamp. FK ids are already
  resolved to human labels (status name, user full name, supplier, location, etc.).
- **`AuditTrailController`** exposes a global, filterable audit browser
  (`/audit-trail/v2`). We do **not** modify it.
- **DomPDF** (`barryvdh/laravel-dompdf`) is installed and already used by
  `ISTController::receiptPdf($id)` →
  `Pdf::loadView('ist.receipt', [...])->download('IST-....pdf')`, with the blade
  at `resources/views/ist/receipt.blade.php`. The receiving receipt mirrors this.
- Both procurement pages already use a `.po-tab-btn` tab bar
  (`PurchaseOrderPage.jsx`: `create` / `status`;
  `PurchaseRequestPage.jsx`: `create` / `my-requests` / `approvals` / `stock-parameters`),
  so a new tab drops in with the established markup.
- `PurchaseOrderController::show()` already eager-loads `receivings`, and the PO
  detail modal already lists receiving sessions — the natural home for a per-session
  receipt button.

**No schema changes.** No new audit data. The GRN reference number reuses the
existing `receivings.receiving_number`.

## Component 1 — Per-record History (PR + PO)

### Backend

**`app/Services/History/RecordHistoryService.php`** (new, single purpose)

- Input: a table name (`purchase_orders` | `purchase_requests`) and a record id.
- Pulls that record's `audit_trail` rows (`where table_name`, `where record_id`,
  ordered `created_at asc`), each row joined to its `user`.
- Folds rows into **curated milestones**. Derivation rules:
  | Audit row | Milestone |
  |-----------|-----------|
  | `action = create` | **Created** |
  | `update` where `status_id` changed | **Status → {new status name}** (already label-resolved by Auditable, e.g. Approved / Authorized / Partially Received / Completed / Rejected) |
  | `update` where `verification_status` changed | **Verified** (`→ verified`) or **Rejected** (`→ rejected`) |
  | `update` with neither of the above | **Edited** |
  | `delete` | **Archived** |
- Each milestone carries: `type` (slug used for the frontend icon), `label`,
  `actor_name`, `timestamp` (ISO8601), and `changes: [{ field, from, to }]`
  built from the row's old/new values (skipping timestamp noise), so the frontend
  can expand it.
- The service returns the ordered milestone array. It does **not** know about HTTP.

**Per-model enrichment** (kept in the controllers, not the generic service):

- **PO history** additionally merges **receiving-session milestones**: for each
  `Receiving` where `po_id = X`, a milestone
  `Received ({session qty} pcs) · {receiver name}` at the session's
  `receiving_date`/`created_at`. Merged into the timeline and re-sorted by time.
- **PR history** additionally merges the **PR → PO conversion** milestone: if a
  `PurchaseOrder` references this PR (`pr_id = X`), a milestone
  `Converted to PO {po_number}` at the PO's `created_at`.

### Endpoints (guarded exactly like the existing `show()` for each resource)

- `GET /purchase-orders/{id}/history`
- `GET /purchase-requests/{id}/history`

Both live inside the same route middleware group as the existing PO/PR reads, so
location-scoping and permissions carry over unchanged. Response shape:

```json
{
  "success": true,
  "data": [
    {
      "type": "created|status|verified|rejected|edited|received|converted|archived",
      "label": "Approved",
      "actor_name": "Admin User",
      "timestamp": "2026-07-14T02:11:00Z",
      "changes": [
        { "field": "status", "from": "Pending", "to": "Approved" },
        { "field": "approved by", "from": null, "to": "Admin User" }
      ]
    }
  ]
}
```

### Frontend

- **`RecordHistoryTimeline.jsx`** (new shared component under
  `frontend/src/admin/components/`): renders the milestone array as a vertical
  timeline. Each milestone shows an icon (by `type`), label, actor, and relative/
  absolute date; a `▾` toggle expands the `changes` list rendered as
  `field: from → to`. Styled with existing `ak-`/design-system primitives.
- **PurchaseOrderPage.jsx**: add a **History** `.po-tab-btn` tab. The tab shows the
  same PO list the Status tab uses; selecting a PO (or opening its detail modal)
  renders `RecordHistoryTimeline` fed from `GET /purchase-orders/{id}/history`.
- **PurchaseRequestPage.jsx**: add a **History** tab that, for a selected PR,
  renders the same component fed from `GET /purchase-requests/{id}/history`.
- API helpers added to `frontend/src/admin/services/api.js`
  (`purchaseOrdersAPI.history(id)`, `purchaseRequestsAPI.history(id)`).

## Component 2 — PO Receiving Receipt (GRN)

### Backend

- **Blade** `resources/views/receiving/receipt.blade.php` (new) — a GRN document:
  header (company, GRN = `receiving_number`, PO number, supplier, destination
  warehouse, receiving date), a line table (Product, Ordered, Received, Condition),
  totals (total received, total damaged), and a "Received by / Checked by"
  signature block. Mirrors `resources/views/ist/receipt.blade.php` styling.
- **`ReceivingController::receiptPdf($id)`** — loads the receiving with
  `purchaseOrder.supplier`, `location`, `receivedBy`, `details.product`, then
  `Pdf::loadView('receiving.receipt', ['receiving' => $r])->download('GRN-{number}.pdf')`.
  Mirrors `ISTController::receiptPdf` exactly.
- **`ReceivingController::receiptCsv($id)`** — streamed CSV of the same line data,
  reusing the streamed-CSV style already in `PurchaseOrderController::export`.
- **Routes** (same middleware group / permission as receiving reads):
  - `GET /receivings/{id}/receipt/pdf`
  - `GET /receivings/{id}/receipt/csv`

### Frontend

- **`ReceivingReceipt.jsx`** (new): a modal that renders the GRN on screen from the
  receiving record and offers **Print** (`window.print()` scoped to the receipt
  container via a print-only stylesheet), **Download PDF**, and **Download CSV**
  (the latter two hit the endpoints above).
- **Entry points:**
  1. PO detail modal (`PurchaseOrderPage.jsx`) — a **Receipt** button on each
     listed receiving session opens `ReceivingReceipt` for that session.
  2. `BarcodeScan.jsx` receiving-complete step — a **View / Print receipt** action
     after a receiving session is saved.

## Testing

**Backend (PHPUnit feature tests):**
- PO history endpoint returns milestones in chronological order, including a
  receiving milestone after a receiving session, with correct `changes` payloads.
- PR history endpoint returns the create milestone and a `Converted to PO`
  milestone once a PO is created from it.
- History endpoints respect the same access control as `show()` (scoped user
  cannot read another location's record).
- `receiptPdf` returns `200` with `application/pdf` for a received PO;
  `receiptCsv` returns `200` with CSV; unknown id → `404`.

**Frontend:**
- `RecordHistoryTimeline` renders milestones and expands/collapses change detail.
- `ReceivingReceipt` renders line data and wires the print/download actions.

## Scope boundaries (YAGNI)

- History tabs on **PR and PO only**. **Purchase Returns untouched.**
- Receipt on **PO Receiving only**.
- No changes to the global Audit Trail v2 page or to `audit_trail` capture.
- No new database tables or columns.
