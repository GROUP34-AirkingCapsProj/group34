# Remove Approval Queue, Relocate PO Approval

**Date:** 2026-06-26
**Branch:** backupV3
**Status:** Approved design

## Problem

The standalone **Approval Queue** page (`/admin/approval-queue`, `ApprovalQueue.jsx`) is
redundant. The modules that need approvals already provide them in their own pages:

- **Purchase Request approval** lives in `PurchaseRequestPage.jsx`
  (`handleApprove`/`handleReject`, permission `purchase_request_approval.approve`).
- **Transfer approval** lives in `TransferRequestPage.jsx`
  (`handleApprove`/`handleReject`, transfer request approve/reject).

The Approval Queue has two tabs:

- **Stock Transfer** tab — duplicates the Transfer Requests page.
- **Purchase Orders** tab — the *only* unique function: approve/reject pending POs by
  calling `purchaseOrdersAPI.update(po_id, { status_id, approved_by })` to set the PO to
  `Authorized` or `Rejected`.

PO approval must be kept because once a PO is authorized it becomes visible/receivable at
its delivery location (existing downstream behavior, unchanged by this work). We therefore
delete the Approval Queue page and relocate **PO approval** into the **Purchase Orders**
page. The transfer tab is dropped entirely (already covered by Transfer Requests).

## Goal

- Delete the Approval Queue page and its routes/nav entries.
- Relocate PO approve/reject into the Purchase Orders page (`PurchaseOrderPage.jsx`,
  the *PO Status* tab) — on both the PO card and the detail modal.
- Move the pending-approval count badge onto the Purchase Orders nav item.
- No backend controller or permission changes: approval continues to flow through the
  existing `purchaseOrdersAPI.update` path.

## Current architecture (for reference)

- `App.jsx`
  - `/admin/approval-queue` → `<ApprovalQueue />` (the ADMINISTRATION sidebar item)
  - `/admin/purchase-orders` → `<ApprovalQueue initialTab="purchase-orders" />`
    (no sidebar entry; legacy alias)
  - `/admin/draft-po-creator` → `<PurchaseOrderPage />` (the "Purchase Orders" sidebar item)
- `PurchaseOrderPage.jsx` — tabs: *Convert PR → PO* (create) and *PO Status* (read-only
  list with View Details + Archive). Currently has **no** approve/reject.
- Nav access: both "Approval Queue" and "Purchase Orders" require
  `purchase_order_creation.read`, so any approver who could see the queue already sees the
  Purchase Orders page. **No access gap is introduced.**
- Badge: `pendingApprovalCount` in `AdminLayout.jsx` is fed by
  `layoutAPI.getSummary().total_pending` and rendered next to the `/admin/approval-queue`
  nav item in `Sidebar.jsx`.

## Changes

### 1. Remove the Approval Queue page

- Delete `frontend/src/admin/pages/ApprovalQueue.jsx`.
- `App.jsx`:
  - Remove the `ApprovalQueue` lazy import.
  - Replace the `/admin/approval-queue` route with a redirect to `/admin/draft-po-creator`.
  - Replace the `/admin/purchase-orders` route with a redirect to `/admin/draft-po-creator`.
  - (Redirects preserve old bookmarks/links instead of 404ing.)
- `Sidebar.jsx`: remove the `{ name: 'Approval Queue', path: '/admin/approval-queue', ... }`
  item from the ADMINISTRATION section.
- `frontend/src/admin/utils/navBundles.js`: remove the `Approval Queue` bundle entry.
- `back-end/database/seeders/NavBundleSeeder.php`: remove the `Approval Queue` row.
  Re-run `NavBundleSeeder` against the dev DB after the change.

### 2. Relocate PO approve/reject into `PurchaseOrderPage` (PO Status tab)

Port the PO approval logic from `ApprovalQueue.jsx` (`handlePOAction`) into
`PurchaseOrderPage.jsx`:

- `approve`: look up the purchase-order status whose name matches `authorized`, call
  `purchaseOrdersAPI.update(po_id, { status_id: authorizedId, approved_by: currentUserId })`,
  then refresh the PO Status list.
- `reject`: look up the `rejected` status id, prompt for a rejection reason using the shared
  confirm-with-reason dialog (`confirmAction`, consistent with PR/Transfer reject), call
  `purchaseOrdersAPI.update(po_id, { status_id: rejectedId, approved_by: currentUserId })`,
  then refresh the list.
- Status ids are resolved via `statusAPI.getAll({ category: 'purchase_order' })` (same as the
  existing queue). Surface the existing fallback toasts when a status id is missing or the
  server returns 403.
- Use `toast` for success/error feedback and a per-PO action-loading flag to disable buttons
  while a request is in flight.

**Gating:** define `canApprovePO = isAdmin || can('purchase_order_creation', 'update')`
(mirrors the current queue). PO creators without `update` do not see approve/reject.

**Card (PO Status list, `renderPoStatusView`):** for POs whose resolved status is `pending`,
render **Reject** and **Approve** buttons next to *View Details* (gated by `canApprovePO`).

```
PO Status tab
┌───────────────────────────────────┐
│ PO-0042   [PENDING]    HIGH        │
│ Supplier · 4 items · ₱120,000      │
│ [View Details] [Reject] [Approve]  │
└───────────────────────────────────┘
```

**Detail modal:** when the opened PO is `pending` and `canApprovePO`, show the same
Reject/Approve in the modal footer (alongside the existing Export buttons) so the approver
can review line items before deciding. On success, close/refresh so the status reflects the
change.

### 3. Badge relocation

- `Sidebar.jsx`: move the `pendingApprovalCount` badge render from the `/admin/approval-queue`
  item to the `/admin/draft-po-creator` ("Purchase Orders") item.
- No change to `AdminLayout.jsx` or `layoutAPI.getSummary()` — the data source and the
  `pendingApprovalCount` prop are unchanged.

### 4. Cleanup check (orphaned helpers)

`frontend/src/admin/utils/approvalNotifications.js` contains older **localStorage**-based
PO-queue helpers (`getApprovalQueuePurchaseOrders`, `saveApprovalQueuePurchaseOrders`,
`addApprovalQueuePurchaseOrder`, `updateApprovalQueuePurchaseOrderStatus`,
`mergeApprovalQueuePurchaseOrder`, `removeApprovalQueuePurchaseOrder`,
`APPROVAL_QUEUE_STORAGE_KEY`) that the current server-driven flow no longer uses.

During implementation, grep for remaining importers of these PO-queue exports. Delete only
the genuinely-orphaned PO-queue exports. **Leave the system-notification helpers**
(`getSystemNotifications`, `addSystemNotification`, `getUnreadNotificationCount`,
`markAllNotificationsRead`, `markNotificationRead`) and the restock helpers untouched unless
they too prove orphaned. No behavior change.

## Out of scope

- No changes to backend PO controllers, status definitions, or permissions.
- No change to the PR or Transfer approval flows.
- No change to the downstream "authorized PO appears at delivery location" behavior.
- No redesign of the Convert PR → PO tab or the PO detail modal beyond adding the
  approve/reject controls.

## Acceptance criteria

1. The "Approval Queue" sidebar item no longer appears for any role.
2. Visiting `/admin/approval-queue` or `/admin/purchase-orders` redirects to the Purchase
   Orders page (no 404, no `ApprovalQueue` render).
3. On the Purchase Orders page → *PO Status* tab, a user with
   `purchase_order_creation.update` (or admin) sees Reject/Approve on pending POs, on both
   the card and the detail modal; a user without it does not.
4. Approving sets the PO to Authorized (with `approved_by`) on the server; rejecting (with a
   reason) sets it to Rejected; the list refreshes to reflect the new status.
5. The pending-approval count badge renders on the Purchase Orders nav item.
6. `ApprovalQueue.jsx` is deleted and no longer imported anywhere; `navBundles.js` and
   `NavBundleSeeder.php` no longer contain the Approval Queue entry.
