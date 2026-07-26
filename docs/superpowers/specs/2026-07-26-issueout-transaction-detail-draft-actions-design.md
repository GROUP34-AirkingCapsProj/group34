# Issue-Out Transaction Detail, Draft Actions, and Barcode Order-Building — Design

**Date:** 2026-07-26
**Branch:** `backupV3`
**Status:** Approved (brainstorming), pending implementation plan.

## Goal

Two regressions/gaps surfaced after the issuance wizard redesign (Tasks 21–26):

1. The Transactions page is a flat, read-only list. An abandoned **Draft** issue-out (customer + products, never confirmed) sits there indistinguishable from real activity, with no way to resume or clear it.
2. The old issue-out flow let a user **scan barcodes** to quickly record the products being issued. The new wizard's OrderBuilder only offers product-picker dropdowns; the scan-to-add convenience was dropped.

This design makes issue-out rows on the Transactions page **clickable into a full detail view**, makes **Draft** issue-outs **resumable or cancelable**, adds a real **Cancelled** status so cancellation reads correctly in history, and **restores barcode scan-to-add** in the OrderBuilder.

**Scope decision (confirmed):** clickable detail + draft actions apply to **issue-outs (issuances) only**. Receiving / Transfer / Adjustment rows are unchanged.

**Cancel semantics (confirmed):** cancelling a Draft **marks it Cancelled and keeps it in history** (greyed/tagged, not resumable, no stock impact) — it is never deleted.

**Cancelled status (confirmed):** add a real `Cancelled` issuance status (not a reuse of `Voided`).

## Non-goals

- No detail views or new actions for receiving/transfer/adjustment rows.
- No change to the FIFO pick scanner (step 3) — the barcode restore is only about the order-building step.
- No change to reservation/stock logic. A Draft never reserved stock, so cancel has no inventory effect.

---

## Part A — Transactions page: clickable issue-out detail + draft actions

### A1. New `Cancelled` issuance status

- Add a `Cancelled` row to `status_lookup` under `status_category = 'issuance'`, following exactly how `Draft` was added (auto id, resolved by name — never hardcoded).
- Idempotent seeder/migration; applied to **both** `airkingV5` and `airkingV5_test`.
- `App\Support\IssuanceStatus` gains a `cancelled(): int` helper (name-resolved, matching `draft()/pending()/completed()/voided()`).

### A2. Cancel endpoint

- **Route:** `POST /issuances/{id}/cancel`, gated `permission:inventory_issuance,create` (the same actors who create/build drafts).
- **Behavior:** loads the issuance under a transaction with `lockForUpdate()`; **only** a `Draft` may be cancelled. Any other status → `422` with a clear message (no state change). On success, sets `status_id = IssuanceStatus::cancelled()` and returns the updated issuance.
- **Idempotency:** cancelling an already-Cancelled issuance returns `422` (not a second transition).
- No stock/reservation changes (a Draft holds none).
- **Permission-gap guard (per standing rule):** before wiring, confirm `inventory_issuance,create` is held by a test actor and by the roles that build drafts (the same gate `confirmQuote` / draft-line endpoints already use). If the actor that creates a draft cannot also cancel it, adjust the gate. A test must exercise the endpoint as a holder of that permission.

### A3. Detail view (frontend)

- On the Transactions page (`TransactionManagement.jsx`), an **issue-out row** becomes clickable → opens a **detail modal** (shared confirm/modal conventions, `ak-*`).
- Data source: `GET /issuances/{id}` (existing `show`). The modal renders:
  - Header: issuance number, status chip, customer, issued-by, date, location; DR number when Completed.
  - Lines: product name, quantity, unit price, line total (grouped if set groups are present, else flat — reuse the quote/detail shape already available).
  - Totals: subtotal, discount, grand total.
- **Draft** status → the modal footer shows **Continue** and **Cancel issue-out**:
  - **Continue** → navigate to `/admin/issuance-scan?issuance=<id>`; the wizard rehydrates from the URL (built in Task 24) and lands on the correct step.
  - **Cancel issue-out** → shared confirm dialog → `POST /issuances/{id}/cancel`; on success the row re-renders as **Cancelled** (the list refetches).
- **Pending / Completed / Voided / Cancelled** → read-only detail, no action buttons.
- Row affordance: issue-out rows show a pointer/hover state; other transaction types are visually unchanged and not clickable (this scope).

### A4. Status display

- The Transactions page and the wizard already read status by name. Ensure `Cancelled` gets a sensible chip style (greyed/neutral, like Voided) in `TransactionManagement.jsx` `getStatusClass` (and anywhere issuance status chips render).
- The wizard's `isTerminal` handling (Task 24) already treats any status that is not Draft/Pending/Completed as terminal/read-only, so a resumed Cancelled issuance renders read-only automatically — verify this holds for `Cancelled`.

---

## Part B — Restore barcode scan-to-add in the OrderBuilder

- Add a **barcode input** at the top of the OrderBuilder line-building area (step 1), auto-focused, submits on Enter, clears after each attempt.
- On submit, resolve the barcode to a product using the existing product/barcode resolution available to the frontend (reuse the product-search/lookup the OrderBuilder already uses, matching on barcode; the plan picks the concrete endpoint). Then:
  - If the product is not already a line → add it via `addCustom` (qty 1).
  - If it is already a line → increment its quantity (via `updateLine`).
  - Unknown barcode → inline error toast; no line added.
- Quantities remain inline-editable; the existing pickers (add appliance / add set / add custom) stay.
- This is order-building only; it does **not** enforce FIFO (that is step 3's job).
- Appliance set-suggestion behavior is unchanged — scanning simply adds the product line; the existing suggested-set card still appears when an appliance is added through the appliance path. (Scan-to-suggest is out of scope for now.)

---

## Files (anticipated)

**Backend**
- New migration: add `Cancelled` issuance status row (idempotent).
- `app/Support/IssuanceStatus.php` — `cancelled()` helper.
- `app/Http/Controllers/Api/IssuanceController.php` — `cancel()` method (Draft-only, locked, idempotent).
- `routes/api.php` — `POST /issuances/{id}/cancel`.
- Tests: `tests/Feature/...` — cancel Draft succeeds → Cancelled; cancel non-Draft (Pending/Completed) rejected 422; double-cancel rejected 422; permission holder can call.

**Frontend**
- `src/admin/pages/TransactionManagement.jsx` — clickable issue-out rows + detail modal + draft Continue/Cancel + `Cancelled` chip style.
- `src/admin/components/issuance/OrderBuilder.jsx` — barcode scan-to-add input.
- `src/admin/services/api.js` — `issuancesAPI` gains `cancel(id)` (and `get(id)` if not already present) in `apiRequest` style.

## Testing

- **Backend (has suite):** TDD the `cancel()` endpoint — happy path (Draft→Cancelled), guards (non-Draft 422, double-cancel 422), and a permission-holder call. Migration applied to both DBs; full suite stays green (currently 607 passed / 1 skipped).
- **Frontend (no suite):** verify with `npm run build` (clean, no new warnings) per changed file; manual `npm run demo` pass: open Transactions, click a Draft issue-out → Continue resumes the wizard; Cancel marks it Cancelled and it stays in history; scan a barcode in the OrderBuilder and confirm the line is added/incremented.

## Risks / notes

- Adding a status row touches `status_lookup` in both DBs and any place that enumerates issuance statuses (status chips, filters) — audit those so `Cancelled` renders, not a blank/"Other".
- The cancel endpoint is a controller task → run the permission-gap check first (A2).
- Barcode resolution at order-build time must not be confused with the FIFO `resolveScan` used at picking; order-building only needs product identity, not zone/FIFO logic.
