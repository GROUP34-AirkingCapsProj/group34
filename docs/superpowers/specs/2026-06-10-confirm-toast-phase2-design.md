# Confirm + Toast Rollout — Phase 2 (PO, Budget, Adjustment, Transfer/IST)

**Date:** 2026-06-10
**Status:** Approved for planning
**Builds on:** `2026-06-09-toast-feedback-messages-design.md`, `2026-06-10-confirm-dialogs-design.md`

## Problem

Phase 1 added shared `toast` (`utils/toast.js`) and `confirm` (`utils/confirm.js`) helpers and applied them to Purchase Request, the archive pages, and the misc `window.confirm` sites. Several high-value CRUD pages were left on the old patterns: static success/error banners and unconfirmed create/submit/dispatch actions. This phase extends the same helpers to Purchase Order, Budget Allocation, Adjustment, Stock Transfer management, and the IST create/dispatch/receive pages.

## New decision (changes a Phase-1 default)

Phase 1 deliberately did **not** confirm submit/create. For these money- and inventory-affecting forms, the user has chosen to **confirm submit/create** as well. So this phase adds a confirmation before submit/create on the listed pages (tone `primary`).

## Scope

Reuse the existing helpers — no new helper code. Conventions from Phase 1 apply: title = action, error toasts blame the system, `primary` tone for approve/create/dispatch/receive, `danger` for destructive. Build gated with plain `npx react-scripts build` (not `CI=true`).

| Page | Change |
|------|--------|
| `PurchaseOrderPage.jsx` | Confirm before **submit PO** (`primary`); replace the `po-success-banner` / `po-submit-error` submit banners with `toast.success` / `toast.error`. (Archive already confirms via `ArchiveButton`.) |
| `BudgetManagementPage.jsx` | Confirm before **create** and **edit-save** (`primary`); replace create/edit banners (`po-success-banner`, `po-submit-error` for `createError`/`editError`) with toast; replace the native `alert()` in `handleArchive` with `toast.error`. (Archive confirm already via `ArchiveButton`.) |
| `AdjustmentManagement.jsx` | Confirm before **create/update** (`handleSubmit`, `primary`). Already uses toast; delete already confirmed via `ArchiveButton`. |
| `StockTransferManagement.jsx` | Confirm before **create/update** (`handleSubmit`, `primary`). Already uses toast; delete already confirmed via `ArchiveButton`. |
| `ISTCreatePage.jsx` | Confirm before **submit** (`handleSubmit`, `primary`); route submit feedback through toast. |
| `ISTDispatchPage.jsx` | Confirm before **confirm-dispatch** (`handleConfirm`, `primary`, e.g. "Confirm dispatch of {transfer_number}?"); replace the `po-submit-error` validation/error banner with `toast.error`. Keep the existing full-page "Dispatch Confirmed" success screen. |
| `ISTReceivePage.jsx` | Confirm before **confirm-receive** (`handleConfirm`, `primary`); replace `po-submit-error` banner with `toast.error`. Keep the existing success screen. |

**Excluded:** `ISTManagementPage.jsx` (list/worklist, no destructive actions).

## Detailed behavior

**Submit/create confirm pattern:**
```js
if (!(await confirmAction({ title: '<Submit this …?>', confirmText: '<Verb>', tone: 'primary' }))) return;
// …existing validation + API call…
toast.success('<Past-tense outcome>');   // replaces success banner
// catch → toast.error("Couldn't <verb>", err?.message || '<fallback>')  // replaces error banner / alert
```
Place the confirm **after** cheap client-side validation where that already exists (so users aren't asked to confirm an obviously-invalid form), OR before — implementer picks the option that reads cleanest per handler, but validation messages must still reach the user via toast.

**Banner removal:** when a `po-success-banner` / `po-submit-error` block (and its backing state, e.g. `submitError`, `submitOk`, `createError`, `editError`) becomes unused after switching to toast, remove the JSX and the dead state. Use a grep guard before deleting any shared class/state (mirrors the Phase-1 lesson that `po-success-banner` is shared — verify per file that the state/JSX isn't used elsewhere in that file; the CSS classes themselves stay, as other pages still use them).

**IST dispatch/receive:** the `handleConfirm` action performs the real inventory move — gate it behind `confirmAction`. Keep validation guards (e.g. "Scan at least one unit…") but surface them via `toast.error` instead of the inline banner. Do **not** remove the success screen.

## Plan decomposition

One task per page (7 tasks), each independently reviewable:
1. PurchaseOrderPage
2. BudgetManagementPage
3. AdjustmentManagement
4. StockTransferManagement
5. ISTCreatePage
6. ISTDispatchPage
7. ISTReceivePage

No unit tests for these (they are page integrations using already-tested helpers); each task is gated by a clean `react-scripts build` and a grep guard for removed identifiers. Manual smoke per page: confirm appears, cancel aborts, success/error toast shows, no static banner left behind.

## Risks

- **Banner state removal:** verify each removed `submitError`/`submitOk`/`createError`/`editError` (or similar) is not read elsewhere in the same file before deleting (grep guard). Some of these files use `submitting` for button state — keep that.
- **Async conversion:** any handler newly using `await confirmAction` must be `async` (these are mostly `useCallback(async …)` already).
- **IST success screens:** must remain; only the error/validation banner is replaced.
- **Double-submit:** submit handlers already gate on a `submitting` flag — keep it; the confirm dialog is modal so no new race.
- **Consistency call:** Adjustment + StockTransfer create/edit get a confirm by extension of the user's PO/Budget decision; revisit if it feels naggy in practice.
