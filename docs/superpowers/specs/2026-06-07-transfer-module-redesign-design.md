# Transfer Module Redesign — Design Spec

**Date:** 2026-06-07
**Status:** Approved for planning
**Branch target:** new feature branch off `main`

## Problem

The interbranch transfer feature is both messy and incorrect. Two parallel
transfer systems write the same tables (`transfers`, `transfer_details`,
`inventory`) with different and conflicting logic:

| | Direct transfer | Request → IST workflow |
|---|---|---|
| UI | `StockTransferManagement`, `TransferScanWorkflow` | `TransferRequestPage`, `IST*` pages |
| API | `TransferController` (`/transfers`) | `TransferRequestController` + `ISTController` |
| Approval | none | request → approve → reject |
| Movement | instant, or ship-then-`receive` | scan → confirm-dispatch → receive |
| Serials | ignored entirely | tracked via `item_serial` |

### Concrete correctness bugs

1. **Inventory columns drift.** `TransferController` updates both
   `quantity_on_hand` and `available_quantity`; the IST flow only ever touches
   `quantity_on_hand`. `available_quantity` silently desyncs depending on the
   path used.
2. **Serialized appliances break stock counts.** IST dispatch never decrements
   source `quantity_on_hand` for appliances and receive never increments it at
   the destination — it only moves `item_serial.location_id`. `TransferController`
   does the opposite: moves the count but never moves the serial. Either path
   leaves counts and serials disagreeing.
3. **Serials teleport early.** IST `confirmDispatch` sets
   `item_serial.location_id = to_location_id` at dispatch, so units show as
   already at the destination while still "In Transit."
4. **Unseeded statuses.** The migration seeds only Pending/Approved/Rejected/
   Partially Received. `In Transit` and `Received` are looked up at runtime and
   throw a 500 if missing — the same reproducibility trap the warranty module hit.
   `Partially Received` is seeded but never used.
5. **Race-prone document numbers.** `TR-REQ-…`, `IST-…` built from
   `max(transfer_id)+1` — not unique- or transaction-safe.
6. **Two Eloquent models on one table** — `Inventory` and `ProductInventory`
   both map to `inventory`.

## Goal

Make the request → approve → IST → receive workflow the single correct process,
with all inventory and serial movement owned by one shared service. Keep a
direct transfer **only** as an admin override that reuses that same engine.

## Chosen approach

**Approach A — single shared `TransferService` engine.** Extract all inventory
and serial movement into one service (`dispatch()`, `receive()`, `reverse()`).
The IST flow and the admin-only override both call the same engine, so the two
paths can never disagree again. (Rejected: B — fix each controller in place,
keeps duplicated logic and divergence risk; C — collapse into one fat
controller, large risky rewrite touching all IST/Request UI.)

---

## Section 1 — Canonical flow, statuses & roles

The single transfer lifecycle:

```
REQUEST                 APPROVE/REJECT          IST (fulfill)              RECEIVE
─────────               ──────────────          ────────────              ───────
Requester creates  →    Admin/IA reviews   →    Admin/IA creates IST  →   Dest confirms
transfer request        (sees source stock)     from approved request     receipt (single
(Pending)               → Approved / Rejected    Source scans/enters       action) → Received
                                                  barcodes → dispatch
                                                  (In Transit)
```

### Statuses (category `transfer`, always resolved by name, never hardcoded IDs)

| Status | Set when | Applies to |
|---|---|---|
| `Pending` | request submitted | request row |
| `Approved` | admin/IA approves; IST inherits on creation | request + IST |
| `Rejected` | admin/IA rejects | request row |
| `In Transit` | source confirms dispatch | IST |
| `Received` | destination confirms receipt | IST |

`Partially Received` is dropped from the workflow: partial dispatch auto-closes
the remainder, and a single receipt action goes straight to `Received`. A
migration/seeder guarantees `In Transit` and `Received` exist so runtime lookups
cannot 500.

### Roles

| Action | Who |
|---|---|
| Create request | warehouse personnel, branch manager, admin, inventory analyst |
| Approve / reject + create IST | admin, inventory analyst |
| Dispatch at source (scan) | admin, inventory analyst, branch manager, warehouse personnel — scoped to source location |
| Receive at destination | admin, inventory analyst, branch manager, warehouse personnel — scoped to dest location |

Non-admin/IA users are restricted to ISTs where their location
(`enforced_location_id ?? location_id`) matches the relevant side. Inventory
analyst is included on dispatch/receive because admin/IA create the IST; this
was an explicit assumption confirmed during design.

---

## Section 2 — The `TransferService` engine

`app/Services/TransferService.php` owns all inventory and serial movement.
Controllers become thin: validate, authorize, call one method, return the
result. Every method runs inside a DB transaction with `lockForUpdate()` on the
affected `inventory` rows. Status is resolved by name via a private helper
(category `transfer`), the same pattern as `WarrantyClaimService`.

### `dispatch(Transfer $ist): Transfer` — Approved → In Transit

Guard: IST status is `Approved` and total `quantity_scanned >= 1`.

Per line, **shipped = `quantity_scanned`** (this is the auto-close:
`quantity_transferred <- scanned`; the unsent remainder is never moved):

- **Source inventory** decremented by `shipped` on **both** `quantity_on_hand`
  and `available_quantity`, for **every** product type (fixes the bug where
  appliances never decremented the count).
- **Serialized appliances:** the scanned serials flip `serial_type ->
  'transferred'` (in-transit) with `transfer_id` set, and `location_id` **stays
  at the source** — not moved to destination (fixes the teleport-on-dispatch
  bug). The flipped-out serials are exactly what the count decrement
  represents, so count and serial rows agree.
- `total_quantity_transferred <- Σ shipped`; status -> `In Transit`.
- Insufficient stock under the row lock -> `422` (`"Only N available at source"`).

### `receive(Transfer $ist, array $lines): Transfer` — In Transit → Received

Guard: status is `In Transit`; caller's location = `to_location_id` (unless
admin/IA). Single action.

- Default `received = shipped`. Non-serialized lines may record a lower
  `received` (damage/loss); any shipped≠received gap is written to tracking +
  audit, and the IST still closes.
- **Destination inventory** incremented by `received` on both columns.
- **Serialized appliances:** the transfer's in-transit serials become
  `serial_type -> 'active'`, `location_id -> to_location_id`.
- `total_quantity_received <- Σ received`; status -> `Received`.

### `reverse(Transfer $ist): void` — state-aware undo

Used by delete/cancel and the admin override for corrections:

- If `In Transit`: restore source count + reactivate serials at source, clear
  `transfer_id`.
- If `Received`: additionally pull `received` back out of destination and move
  serials back to source.

### Cross-cutting fixes baked in

- **Race-safe document numbers:** stop using `max(transfer_id)+1`. Insert the
  row first, then derive `TR-REQ-…`/`IST-…` from the real `transfer_id` inside
  the same transaction.
- **One inventory model:** standardize on `Inventory`; `ProductInventory`
  (duplicate mapping of `inventory`) is removed or aliased to a single source of
  truth.
- `available_quantity` always moves in lockstep with `quantity_on_hand`,
  everywhere.

### Controller delegation after refactor

- `ISTController::confirmDispatch` -> `TransferService::dispatch()`
- `ISTController::receiveStore` -> `TransferService::receive()`
- `TransferController::destroy` / admin override -> `TransferService::reverse()`
  / `dispatch()` + `receive()`

---

## Section 3 — Stock visibility at approval

The approver (admin/IA) must see live source stock per line while deciding, and
be warned when a request can't be met.

**Backend.** `TransferRequestController::show` already attaches `stock_at_source`
per line. Extend each line's decision payload to:

- `quantity_requested`
- `available_at_source` — source `available_quantity` (the truly transferable
  figure, the same column the engine decrements)
- `shortfall` — `max(0, requested − available_at_source)`

Computed at request-view time, so always current (no stale snapshot). No new
endpoints.

**Frontend (`ApprovalQueue` / `TransferRequestPage` detail).** Each line renders
requested vs available with an amber warning when
`available_at_source < quantity_requested`. The approve button stays enabled
(admin/IA may still approve; dispatch auto-closes the shortfall), but the gap is
explicit. The existing per-line quantity override on approve stays, letting the
approver trim `quantity_requested` before approving.

---

## Section 4 — Notifying source and destination locations

Both a worklist+badge and an alert entry, both computed and location-scoped — no
new notifications table, matching how `DashboardController` derives
`pending_transfers`.

**Source "to fulfill":** an IST (`transfer_request_id IS NOT NULL`) with status
`Approved` whose `from_location_id` = the user's location (admin/IA see all).

### Worklist page + badge

- A "Transfers to Fulfill" list — reuse `ISTManagementPage` filtered to
  `status = Approved` and source = user's location. Each row opens the IST scan
  workflow to scan and dispatch.
- A count badge in the sidebar fed by a new `awaiting_fulfillment` (source-
  scoped) field added to the dashboard `getStats()` payload the sidebar already
  consumes — no new polling loop.

### Alerts/Notices entry

- A derived alert in the existing computed feed: "N interbranch transfer(s)
  awaiting fulfillment at your location", linking to the worklist. Built like the
  current pending-approval alerts, respecting `UserAlertSettings` read-state.

### Destination symmetry

The same mechanism, with source/dest parameters flipped, gives the destination
an "ISTs in transit to you, awaiting receipt" worklist + badge + alert (status
`In Transit`, `to_location_id` = user's location). Included.

---

## Section 5 — Admin-only direct override

Kept as an emergency/correction tool, rewired so it cannot reintroduce
divergence. The old `TransferController` instant/`ship_only` inventory math is
deleted; the override is a thin caller of `TransferService`.

- **Access:** `role:admin` only. Drop `branch_manager`/`warehouse_personnel`
  from the direct `/transfers` write routes. Normal users only see the
  request → IST flow.
- **Behavior:** one express action that creates a transfer flagged as an admin
  override at status `Approved`, sets each line's `quantity_scanned` to the
  chosen quantity (the override substitutes for the scan step), then runs
  `dispatch()` immediately followed by `receive()` — the exact same correct
  movements, without the request/approval gate. No second stock-math path.
  Because the serials are not physically scanned in this path, the override is
  restricted to non-serialized products, or requires the admin to enter the
  specific serial numbers per line so `dispatch()` can flip the correct
  `item_serial` rows; serialized transfers without entered serials are rejected
  with a `422`.
- **Audit:** `AuditTrail` action `admin_direct_transfer`, plus tracking rows, so
  an override is distinguishable from a normal IST in history.
- **Corrections/cancellations:** go through `TransferService::reverse()`, not the
  old ad-hoc `destroy()` math.
- **UI:** `StockTransferManagement` kept but admin-gated (hidden from the sidebar
  for everyone else) and relabeled as a direct/express tool.
  `TransferScanWorkflow`'s old barcode-`receive` against `TransferController` is
  retired in favor of the IST scan/receive screens — one scanning UI.

---

## Section 6 — Error handling & testing

### Error handling

- Guard-clause failures return `422` with a clear message (wrong status,
  location not authorized, nothing scanned, line not on this IST) — never a 500.
- Missing status rows cannot happen at runtime: the migration/seeder guarantees
  `In Transit` + `Received`. The by-name resolver keeps a guarded `500` only as a
  last-resort safety net with a precise message.
- Every service method wraps work in a DB transaction with `lockForUpdate()` on
  touched `inventory` rows; any exception rolls back the whole movement.
- Insufficient stock at dispatch is a `422`, checked under the row lock
  (race-safe).
- Audit writes stay in `try/catch + report()` so an audit failure never rolls
  back a valid transfer.

### Testing

Constraints (from the warranty module): migrations are MySQL-only, so SQLite
`RefreshDatabase` fails. Use the cloned `airkingV5_test` MySQL DB, phpunit
pointed at it, wrapped in `DatabaseTransactions`. New statuses resolved by name.

**`TransferServiceTest` (core):**

- `dispatch` deducts source `quantity_on_hand` and `available_quantity` for both
  consumables and appliances.
- `dispatch` flips appliance serials to in-transit without moving `location_id`
  to destination.
- Partial dispatch: 5 requested, 3 scanned -> 3 shipped, source −3, remainder
  auto-closed, status `In Transit`.
- `receive` increments destination on both columns and moves serials to `active`
  at destination.
- Shipped≠received gap (non-serialized) records the discrepancy and still closes
  `Received`.
- `reverse` from `In Transit` and from `Received` each restore inventory +
  serials correctly.
- Round-trip invariant: request -> approve -> IST -> dispatch -> receive leaves
  total system stock unchanged and source+dest counts reconciled with
  `item_serial` rows.
- Race-safe document numbers: concurrent creates produce unique
  `IST-…`/`TR-REQ-…`.

**Authorization/flow tests:** role + location enforcement on
request/approve/dispatch/receive; admin override runs the same engine and is
audited.

**Reconciliation helper:** for every appliance/location,
`count(item_serial active) == inventory.quantity_on_hand` — the check that would
have caught the original bugs.

**Smoke test:** update the existing `routes/console.php` transfer self-check
command to exercise the new pipeline.

---

## Out of scope

- Back-orders for the auto-closed shortfall (requester re-requests if still
  needed).
- Multi-session partial receipts (single receipt action only).
- Any change to the request creation UX beyond the approval stock-visibility
  additions.
