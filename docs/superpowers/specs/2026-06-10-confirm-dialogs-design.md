# Confirm Dialogs + Action Feedback — Design Spec

**Date:** 2026-06-10
**Status:** Approved for planning

## Problem

Confirmation before important actions is inconsistent across the admin app:
- ~16 actions use the browser's native `window.confirm()` (ugly, unstyled).
- Purchase Request and Transfer Request **reject** flows use bespoke React modals to collect a reason.
- **Approve** fires instantly with no confirmation at all.
- Error feedback in these handlers still uses native `alert()`.

We want one shared, good-looking confirm dialog (matching the new toast styling), used consistently
before approve / reject / cancel / delete-archive, with a reason box where rejection/cancellation
needs one — and the native `alert()` errors in those handlers replaced by the toast.

## Goals

1. A shared confirm-dialog helper (`frontend/src/admin/utils/confirm.js`) built on SweetAlert2,
   styled to match the toast (`swal-toast.css` conventions).
2. Apply confirmation to the high-stakes actions across all pages: **approve, reject, cancel,
   delete/archive-via-window.confirm**.
3. Reject/cancel dialogs capture a reason when one is meaningful.
4. Replace native `alert()` error feedback in touched handlers with `toast.error(...)`, and add a
   `toast.success(...)` where one is not already shown.

## Non-Goals

- **Submit/create confirmations** — deferred per product decision (the success toast is enough).
- **`ArchiveButton.jsx`** — it already has a good inline "Archive? Yes/No" confirm step; leave it
  unchanged. Most `handleArchive` handlers route through it and are therefore already covered.
- Backend/API changes.

## The Helper — `frontend/src/admin/utils/confirm.js`

Two exported async functions sharing one SweetAlert2 mixin and styling.

### `confirmAction(options) → Promise<boolean>`

```js
const ok = await confirmAction({
  title: 'Approve this request?',
  text: 'PR-2026-0042 will move to approved.', // optional
  confirmText: 'Approve',                       // default 'Confirm'
  cancelText: 'Cancel',                         // default 'Cancel'
  tone: 'primary',                              // 'primary' | 'danger'  (default 'danger')
});
if (!ok) return;
```

Resolves `true` when confirmed, `false` when cancelled/dismissed (Esc, backdrop, X).

### `confirmWithReason(options) → Promise<{ confirmed: boolean, reason: string }>`

```js
const { confirmed, reason } = await confirmWithReason({
  title: 'Reject this request?',
  confirmText: 'Reject',
  tone: 'danger',
  reasonRequired: true,            // default false
  reasonLabel: 'Reason for rejection',
  reasonPlaceholder: 'Explain why…', // optional
});
if (!confirmed) return;
```

- Renders a `<textarea>` in the dialog.
- When `reasonRequired` is true, the confirm button is blocked / shows a validation message until
  a non-empty (trimmed) reason is entered (use SweetAlert2 `preConfirm` + `showValidationMessage`).
- On cancel/dismiss, resolves `{ confirmed: false, reason: '' }`.

### Tone → styling

- `tone: 'danger'` → red confirm button + warning/error icon. Used for reject, cancel, delete, archive.
- `tone: 'primary'` → brand-colored confirm button + question/info icon. Used for approve.
- Styling lives in a CSS block appended to `frontend/src/admin/styles/swal-toast.css` (or a sibling
  `swal-confirm.css` imported where toast css is imported — implementer picks one and is consistent),
  reusing the toast's card/border/typography conventions. Buttons use existing SweetAlert2
  `confirmButton`/`cancelButton` custom classes.

### Behavior

- Not a toast: centered modal, requires an explicit choice, does NOT auto-dismiss.
- Keyboard: Esc cancels; Enter confirms when there is no required-but-empty reason.
- Returns a resolved Promise in all paths (never rejects) so callers use a simple `if (!ok) return;`.

## Rollout Targets

For each, the pattern is: **gate the action behind the dialog; on cancel return early; on failure
`toast.error`; on success `toast.success` if not already shown.**

### Approve (add confirm — currently none)
- `PurchaseRequestPage.jsx` `handleApprove` (line ~393): `confirmAction({ tone:'primary', confirmText:'Approve', ... })`; replace `alert(...)` catch with `toast.error("Couldn't approve request", msg)`; add `toast.success('Request approved', ...)`.
- `TransferRequestPage.jsx` `handleApprove` (line ~175): same treatment.

### Reject (replace custom reason modals)
- `PurchaseRequestPage.jsx` `handleReject` (line ~406) + its `rejectModal` state/JSX (`openRejectModal`): replace with `confirmWithReason({ tone:'danger', confirmText:'Reject', reasonRequired:true, reasonLabel:'Reason for rejection' })`. Remove the `rejectModal` state, `openRejectModal`, and the modal JSX. Wire the reject button to call an async handler that opens the dialog. Errors → `toast.error`; success → `toast.success('Request rejected', ...)`.
- `TransferRequestPage.jsx` `handleReject` (line ~192) + its reject modal: same treatment.

### Cancel actions
- Apply `confirmAction` (yes/no, danger tone) where a cancel exists. Use `confirmWithReason` only if a reason is genuinely meaningful for that flow (implementer judgement per site; default yes/no).

### Native `window.confirm()` → `confirmAction` (danger tone)
Convert these 16 sites (handlers become `async`; `if (!window.confirm(msg)) return;` becomes
`if (!(await confirmAction({ title, text, confirmText, tone:'danger' }))) return;`). Preserve the
existing message wording as title/text:
- `DeliveryReceiptManagement.jsx:84` (delete delivery receipt)
- `BarcodeAdjustmentPage.jsx:804` (permanent inventory modification)
- `ArchiveSettingsPage.jsx:334` (archive by settings), `:378` + `:379` (permanent delete — two-step; keep two confirms)
- `ArchiveManagement.jsx:99` (restore), `:123`+`:126` (permanent delete — two-step), `:151` (restore all N)
- `IssuanceScanPage.jsx:913` (remove product from issuance), `:963` (msg)
- `UserManagement.jsx:166` (activate/deactivate user)
- `ReceivingManagement.jsx:150` (delete receiving record)
- `SupplierNetwork.jsx:289` (remove product from supplier)
- `PurchaseReturnManagement.jsx:111` (delete purchase return)
- `ProfitLossManagement.jsx:176` (delete profit/loss record)

For each of these handlers, also replace any native `alert()` error in the same handler with
`toast.error(...)` and add a `toast.success(...)` on success where one is not already shown.

## Plan Decomposition

- **Task 1:** Build + unit-test `confirm.js` (the helper) and its styling. No callers yet.
- **Subsequent tasks: one per page/module** for the rollout, so each is small and independently
  reviewable. Suggested grouping:
  - Purchase Request (approve + reject + archive already via ArchiveButton; just approve/reject + alert→toast)
  - Transfer Request (approve + reject)
  - Archive pages (`ArchiveManagement`, `ArchiveSettingsPage`) — multi-step confirms
  - Remaining `window.confirm` pages (DeliveryReceipt, BarcodeAdjustment, IssuanceScan, UserManagement, Receiving, SupplierNetwork, PurchaseReturn, ProfitLoss) — can be one task each or grouped 2-3 by similarity.

## Testing

**Unit (`frontend/src/admin/utils/confirm.test.js`, mock `sweetalert2`):**
- `confirmAction` resolves `true` when the mocked dialog reports confirmed (`{ isConfirmed: true }`),
  `false` otherwise.
- `confirmAction` passes the right `confirmButtonText`, `icon`, and danger/primary custom class for each tone.
- `confirmWithReason` returns `{ confirmed, reason }`; with `reasonRequired: true` the `preConfirm`
  validation rejects an empty/whitespace reason (assert validation invoked) and accepts a real one.
- Dismiss path returns `false` / `{ confirmed: false, reason: '' }`.

**Manual smoke (per converted action):** confirm proceeds, cancel/Esc aborts with no side effect,
reject/cancel requires a reason where configured, failure shows an error toast.

## Risks

- **Async conversion:** handlers using `window.confirm` are synchronous-ish; converting to
  `await confirmAction` requires the handler be `async` and any early-return logic preserved. Verify
  no double-submit (disable button / guard while dialog open where a busy flag exists).
- **Removing reject modals:** confirm no other code references the removed `rejectModal` state /
  `openRejectModal` before deleting (grep guard, like the toast task's CSS guard).
- **Two-step deletes** in archive pages must keep both confirmation steps (don't collapse to one).
- **`ArchiveButton` untouched** — do not migrate it; verify rollout tasks don't accidentally alter it.
