# Issue-out: auto-revert abandoned picks to Draft + custom items in pieces

**Date:** 2026-07-27
**Branch:** backupV3
**Status:** Design approved, ready for implementation plan

Two independent fixes to the issue-out (issuance) wizard, reported after the
set/BOQ + FIFO-picking redesign ([[set-boq-fifo-picking-incidents]],
[[issueout-txn-detail-draft]]).

---

## Part A — Custom items are pieces; only Sets use pack/box

### Problem

Issue-out has no "bulk vs pieces" toggle, but the **Add custom item** path silently
forces pack/box entry whenever the chosen product has `pieces_per_package > 1`, and
the Order and Quote review screens render a pack/box breakdown for *every* line —
including a custom line the picker entered as loose pieces. The user's mental model:
a **Set** is the bulk (pack/box) path; a **custom item** is always plain pieces.

### Rule

- A **Set** (pre-defined BOQ) expresses its material quantities in pack/box. This is
  the only "bulk" path in issue-out. Set lines keep their pack/box display.
- A **custom item** is always entered and displayed in plain **pieces**.
- Storage is unchanged: quantity is always persisted in PIECES (this only changes
  entry and display).

### Changes (frontend only, `frontend/src/admin/components/issuance/`)

1. **`OrderBuilder.jsx → AddCustomPath`** — remove the `usePack` branch entirely
   (the `packages`/`pieces` split inputs, the `ppp`/`usePack`/`totalPieces` maths,
   and the "= N pcs · X Box + Y pcs" helper line). The adder always renders a single
   plain **Qty** (pieces) input; `add()` sends that integer piece count.
2. **`OrderBuilder.jsx → LineRow`** — only call `packageBreakdown(...)` when the line
   belongs to a set (`line.issuance_set_id != null`). Custom (loose) lines show plain
   `×N`.
3. **`QuotePanel.jsx → renderLine`** — same gate: pack/box breakdown only for set
   lines; custom lines show `×N` pieces.

No backend change. The real Pick scanner is already pieces-only for issuance
(`FifoPickingService::suggest()` never emits `pieces_per_package`/`package_unit`;
those come only from `TransferService::pickList()`), so `PickScanner` needs no change.

---

## Part B — No stranded "Pending": abandoned picks auto-revert to Draft

### Problem

Issue-out statuses are `Draft → Pending → Completed` (plus `Voided`/`Cancelled`).
`Pending` is not an approval state — it is the *picking* phase (quote confirmed,
stock reserved). Today:

- Abandon while still **Draft** (Order/Quote steps) → stays Draft → resumable
  ("Continue") or cancellable. ✓ Correct.
- Abandon while **Pending** (after "Confirm & Start Picking") → the order is stranded
  as Pending forever. The Cancel endpoint is Draft-only, so it cannot be cleaned up,
  and any already-scanned units have already left stock.

The user wants: an issue-out that is not completed should always be a **Draft** (or
Cancelled) — never a stranded Pending.

### Key constraint: picking deducts stock immediately

Every accepted scan (`IssuanceScanController::scanItem`) deducts inventory
(`quantity_on_hand`/`available_quantity`), deducts the FIFO batch, and — for a
serialized appliance — **deletes** the `ItemSerial` on the spot. Reverting a
picking-phase order to Draft therefore has to *un-pick*: reverse every scanned row.
`removeItem` already performs exactly this reversal for one row (restore inventory,
restore FIFO batch, recreate the serial), so revert reuses that logic across all
issued rows.

**Approved trade-off:** un-picking fully back to a clean Draft is acceptable. If a
picker scanned 8 of 10 and bails, those 8 return to stock and resuming re-picks from
scratch. This guarantees no stranded Pending and correct stock.

### Backend

Factor the per-row reversal currently inside `removeItem` into a shared private
helper `reverseIssuedDetail(IssuanceDetail $detail, Issuance $issuance)` (restore
inventory + `packages_on_hand`, `restoreFifo`, recreate `ItemSerial` for serialized
products, delete the row) so both `removeItem` and the new revert path use one
implementation.

New `revertToDraft(int $id)` on `IssuanceScanController`:

- Route: `POST /api/issuance-scan/{id}/revert-to-draft`, gated
  `inventory_issuance,create` (same set as scan/complete — no permission gap).
- One `DB::transaction` with `Issuance::lockForUpdate()`.
- **Pending-only guard:** already-Draft → no-op success (200, idempotent);
  Completed / Voided / Cancelled / any non-Pending → 422, no mutation.
- For each issued detail (`quantity_issued > 0`): `reverseIssuedDetail(...)`.
- `ReservationService::releaseFor('issuance', $id)` — drop the holds.
- Reset the surviving requested lines' `quantity_short = 0` (keep the requested lines
  so the Draft is resumable and re-quotable).
- `recalculateTotals(...)`, then set `status_id` → `IssuanceStatus::draft()`.
- Return the fresh issuance (or a simple success payload).

### Frontend (`IssuanceScanPage.jsx`)

Fire revert on every catchable exit while the order is Pending-and-not-completed.
An "order is revertable" flag = `issuanceId && isPending && !isCompleted && !isTerminal`.
A ref mirrors the live status so async cleanups read the current value, and a
`revertedRef`/in-flight guard prevents a double-fire (React StrictMode remount,
unmount-after-New-Order).

- **"New Order" button** (`resetAll`) → `await revertToDraft(id)` (best-effort;
  swallow failure with a toast) before clearing state.
- **Unmount / route change mid-pick** → `useEffect` cleanup calls the revert endpoint
  (fire-and-forget). Must NOT fire when the order was completed or when the user is
  intentionally staying/resuming.
- **Tab close / hard refresh** → `navigator.sendBeacon(revertUrl)` in a `beforeunload`
  handler (best-effort).
- New `issuanceScanAPI.revertToDraft(id)` + a `revertToDraftUrl(id)` (for the beacon)
  in `services/api.js`.

### Caveats (accepted)

- **Hard tab-kill is best-effort.** `sendBeacon` normally lands, but a force-killed
  browser can leave the order Pending briefly. It is never lost: still resumable via
  Transactions **Continue** (lands on Pick), and its reservations self-expire after
  `ReservationService::TTL_MINUTES` (240). Once reverted it is a Draft, so the
  existing **Cancel** covers "Draft or cancel".
- **No rename of "Pending"** and **no Pending-cancel path** — a reverted order is a
  Draft, which is already cancellable via the Transactions detail modal.

---

## Testing

- **Part A:** frontend build only (no frontend test suite) — manual `npm run demo`:
  add a custom install material with `pieces_per_package > 1`, confirm the adder shows
  a single pieces field and the Order + Quote rows show `×N` with no pack/box; add a
  Set and confirm its lines still show the pack/box breakdown.
- **Part B (backend, PHPUnit):**
  - Revert a Pending order with no picks → Draft, reservations released, requested
    lines intact, `quantity_short` zeroed.
  - Revert a Pending order with a partial consumable pick → deducted pieces restored
    to inventory + batch, issued row gone, back to Draft.
  - Revert a Pending order with a scanned serialized appliance → `ItemSerial`
    recreated, inventory restored, back to Draft.
  - Idempotency: revert an already-Draft order → 200 no-op; revert a Completed order
    → 422, no mutation.
  - Confirm the shared reversal helper leaves `removeItem` behaviour unchanged
    (existing tests stay green).
- Full backend suite green.

## Out of scope

- Renaming the `Pending` status.
- A Pending-state Cancel action (revert-to-Draft + existing Draft cancel covers it).
- Deferring stock deduction to Complete (large rearchitecture).
- Any change to transfer dispatch picking.
