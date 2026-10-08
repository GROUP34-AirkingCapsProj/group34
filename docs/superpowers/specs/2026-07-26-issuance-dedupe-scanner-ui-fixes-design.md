# Issuance Dedupe, Order/Pick Scanner Rework, and UI Visibility Fixes — Design

**Date:** 2026-07-26
**Branch:** `backupV3`
**Status:** Approved (brainstorming), pending plan.

## Goal

Address a batch of user-reported issues with the issuance wizard and Transactions page:

1. **Duplicate issuance records.** `IssuanceScanController::initSession` does an unconditional `Issuance::create()` — every Order-Setup submit records a new Draft. Repeated starts pile up many issuance records, so the Transactions page shows a long list of separate issue-outs instead of one-transaction-per-row. Root fix, not a list-grouping change.
2. **OrderBuilder clutter.** Step 1 has a Scan-barcode field and an Add-appliance path that are redundant — "Add custom" already covers any non-set product. Remove both.
3. **Pick-step scanner.** The picker should stock-out items by scanning: a manual serial/product-barcode field **and** a camera scanner (like the Receive-PO / ReceivePanel flow), both feeding the FIFO-checked pick.
4. **Transaction detail grouping.** A FIFO pick creates one issued line per scanned unit, so the detail view repeats the same product. Group detail lines by product with a summed quantity.
5. **Hidden UI.** Some buttons/fields in the wizard are effectively invisible (contrast/CSS). Fix with the frontend-design skill after seeing the running app.

## Confirmed decisions
- **One unfinished draft per user, auto-resumed.** A user has at most one open Draft at a time; starting/opening the issuance page drops them into their existing draft rather than creating a new record. To start a genuinely different order they Cancel the current draft (endpoint already exists). This delivers "one transaction, one file, one ID, not duplicated."
- Pick scanner: **manual field + camera** (both feed the same onScan).
- Drafts remain visible/cancelable on the Transactions page (unchanged from the prior feature).

## Non-goals
- No change to FIFO enforcement, reservations, or the scan verdict engine.
- No change to receiving/transfer/adjustment transaction rows.
- Not deleting existing duplicate drafts in the DB automatically (the user can Cancel them; a cleanup is out of scope).

---

## Part 1 — One unfinished draft per user (kills duplicates)

**Backend**
- `IssuanceScanController::initSession`: before creating, look up an existing **open Draft** for the current user (`issued_by = Auth::id()`, `status_id = IssuanceStatus::draft()`, newest first). If found, **return that issuance** (same response shape) instead of `create()`-ing a new one. This makes repeated starts / double-submits idempotent — one draft per user.
- New endpoint `GET /issuance-scan/active-draft` (auth, `inventory_issuance,create`) → `{ success, issuance_id }` where `issuance_id` is the current user's newest open Draft, or `null`.

**Frontend (`IssuanceScanPage.jsx`)**
- On mount, when there is **no** `?issuance=` param, call `active-draft`. If it returns an id, resume that draft (set `?issuance=`, load its quote) instead of rendering a blank Order-Setup form. If null, show the setup form as today.
- The Order-Setup submit still calls `initSession`; because the backend now reuses, a stray double-submit cannot create a second record.
- **New Order** button: when an open draft exists, "New Order" must not silently spawn a parallel draft. It should route the user to their existing draft (or offer to Cancel it first). Simplest: keep "New Order" clearing local state, but since `initSession` now reuses, the next setup submit returns the same draft — no duplicate. (A future explicit "cancel & start new" is out of scope.)

**Tests (backend has a suite):**
- Two `initSession` calls by the same user return the **same** `issuance_id` (no duplicate).
- After the draft is Completed or Cancelled, a fresh `initSession` creates a **new** draft.
- `active-draft` returns the open draft id for the user, and `null` when none/after it leaves Draft.
- Permission-gap check: `active-draft` gated on `inventory_issuance,create` (held by admin/branch_manager/warehouse_personnel — the actors who create drafts).

---

## Part 2 — OrderBuilder cleanup

`OrderBuilder.jsx`:
- Remove the `BarcodeAddPath` component and its `handleBarcodeScan` handler + the "Scan barcode" block (added earlier; superseded by the pick-step scanner).
- Remove the `AddAppliancePath` component, `handleAddAppliance`, and its "Add appliance" block. Keep **Add set** and **Add custom**.
- Remove now-unused imports/state (e.g. the appliance set-suggestion call) so no dead code / new warnings remain.

---

## Part 3 — Pick-step scanner (manual + camera)

- The Pick step currently renders `PickScanner` with a manual barcode input only. Add a **camera scanner** alongside it, mirroring `ReceivePanel`/`ISTReceivePage`: `<CameraScanner onDecode={...} mountId=... storageKey=... busy=... hint=.../>` whose decode feeds the SAME `onScan(barcode, confirmedZone)` path the manual field uses (FIFO-checked). Keep the manual field (type or scan-gun) for serials/barcodes.
- Implementation choice (plan decides): extend `PickScanner.jsx` to optionally render a CameraScanner, OR wrap it in the issuance Pick step. Prefer extending `PickScanner` so transfer dispatch can gain the camera too, but keep the camera optional via a prop so nothing else regresses. The camera decode must go through the same verdict-handling as a manual scan.

---

## Part 4 — Transaction detail grouped by product

- In `TransactionManagement.jsx`'s issue-out detail modal, group the fetched lines by `product_id`: one row per product with **summed quantity** and summed line total (and a representative unit price). The list must not show the same product repeated once per scanned unit. Set-grouping already present in the quote's `set_groups` is preserved; the per-product collapse applies within the flat line list.

---

## Part 5 — UI visibility / hidden buttons

- Launch the app (`npm run demo`) and inspect the issuance wizard + transactions detail for invisible/low-contrast buttons and fields (the user reports buttons and inputs that "are like hidden"). Likely a contrast bug in the `isp-*` / `ak-*` wizard styles (e.g. transparent/near-white buttons on a white card, or input text/border with no contrast).
- Fix with the **frontend-design** skill: ensure every button and input in the issuance wizard and transaction detail has sufficient contrast and a visible affordance in both light and dark, following the existing design system tokens. Verify visually in the running app.

---

## Files (anticipated)

**Backend**
- `app/Http/Controllers/Api/IssuanceScanController.php` — `initSession` reuse; new `activeDraft()`.
- `routes/api.php` — `GET /issuance-scan/active-draft`.
- `tests/Feature/...` — dedupe + active-draft tests.

**Frontend**
- `src/admin/services/api.js` — `issuanceScanAPI.activeDraft()`.
- `src/admin/pages/IssuanceScanPage.jsx` — resume active draft on mount; camera in Pick step.
- `src/admin/components/issuance/OrderBuilder.jsx` — remove Scan-barcode + Add-appliance.
- `src/admin/components/picking/PickScanner.jsx` — optional CameraScanner.
- `src/admin/pages/TransactionManagement.jsx` — group detail lines by product.
- Wizard CSS (`isp-*` / relevant stylesheet) — contrast/visibility fixes.

## Testing
- Backend: TDD the dedupe + active-draft (suite stays green, currently 611 passed / 1 skipped).
- Frontend: `npm run build` clean per task; a manual `npm run demo` pass drives Part 5 and verifies the flow (no duplicate drafts; camera scan picks an item; detail groups by product; buttons/fields visible).
