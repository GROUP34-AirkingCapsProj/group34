# Transfer Module Consolidation & Redesign

**Date:** 2026-07-22
**Branch:** backupV3
**Status:** Approved design, pending implementation plan
**Supersedes (UX/flow):** `2026-06-07-transfer-module-redesign-design.md` — that redesign built the shared
`TransferService` engine (dispatch/receive/reverse, FIFO batch picking, serial integrity). This spec keeps that
engine and rebuilds the *flow, roles, navigation, and picking UX* on top of it.

## Problem

The transfer feature is too complicated and exposes **three separate sidebar items**, each with its own
controller and page:

- **Transfer Requests** → `/admin/transfer-requests` → `TransferRequestController`
- **Stock Transfers** → `/admin/transfers` → `TransferController` (admin express override)
- **Interbranch Transfers** → `/admin/ist` → `ISTController`

Users cannot tell which one to use. The request flow is also modelled wrong for how the business actually
works: today the **requester must choose the source warehouse** (`from_location_id` is `required` at submit),
but in reality the requester does not know who has stock — the **Inventory Analyst (IA)** should find and assign
the fulfilling warehouse.

Separately, a new inventory protocol requires stock to leave a location in **FIFO order**, and pickers need the
system to **tell them which serial number and which zone to pull** — guidance that does not exist in the UI today.

## Goals

1. **One sidebar item — "Transfers"** — backed by a single controller delegating to the existing `TransferService`.
2. **Every movement is a request gated by the IA.** No self-service push. No admin express override.
3. Support **three initiation intents**, all routed through the IA.
4. The IA **verifies, trims, and assigns the source** (pull) / **confirms the destination** (offload) before the
   fulfilling warehouse is notified.
5. **FIFO-guided picking**: on dispatch the system shows the exact serials + zones to pull, oldest stock first.
   Guidance is advisory; FIFO accounting is enforced by count in the engine.
6. Reuse the existing realtime push + worklist badges for notifications.

## Non-goals

- No change to the `TransferService` inventory/serial movement math (dispatch/receive/reverse already correct).
- No change to `inventory_batches` FIFO accounting logic (`deductFifo`, `deductFromReceiving`, `restoreFifo`).
- No new "Partially Received" status — partial dispatch auto-closes the remainder (no back-order).
- No change to issuance/sales logic or the inventory Serial Numbers tab (separate 2026-07-21 work).

## The three initiation intents

Every transfer is one `transfers` row (`transfer_request_id` stays null; the request *is* the transfer, moving
through statuses). All three intents flow through the IA verify/assign gate.

| Intent | Who initiates | Requester sets | IA fills in / confirms |
|---|---|---|---|
| **Location needs stock** (pull) | destination location (WP/BM/IA/admin) | destination = own location; products + qty from **master catalog** | **assigns source** warehouse |
| **Warehouse too full** (offload) | source warehouse (WP/BM/IA/admin) | source = own location; products + qty from **own on-hand stock**; **suggested** destination | confirms or **changes destination** |
| **IA demand transfer** | IA (or admin) | — | picks **both** source and destination + products |

Product-picker source of truth:
- **Pull**: master product catalog (source is unknown at request time), quantity is what the location needs.
- **Offload**: the initiating location's own `inventory` rows (can only offer what it holds).
- **IA demand**: catalog, constrained to what the chosen source holds once source is picked.

## State machine

Statuses come from `status_lookup` where `status_category = 'transfer'` (all resolved by name; the
`2026_06_07_000001_guarantee_transfer_statuses` migration guarantees `In Transit` + `Received` exist).

```
Submitted ──IA verify+assign──▶ Approved ──source scans+dispatch──▶ In Transit ──dest scans+receive──▶ Received
   │  (a.k.a. "Pending")            │  (a.k.a. "To Send")              │ ("To Receive")
   └──────────── IA reject ─────────┴──▶ Rejected
```

- **Submitted** = `Pending` status (tab label "To Verify").
- **Approved** = source assigned + IA-approved (tab label "To Send").
- **In Transit** = dispatched (tab label "To Receive").
- **Received** = finalized, receipt generated.
- **Rejected** = terminal.
- **IA demand transfers** are created directly at **Approved** (IA is initiator + verifier in one step).
- Partial dispatch is allowed; the remainder auto-closes on dispatch (`quantity_scanned` drives shipped qty).

## Navigation & page

Replace the three INVENTORY INTELLIGENCE sidebar entries (`Transfer Requests`, `Stock Transfers`,
`Interbranch Transfers` in `frontend/src/admin/components/Sidebar.jsx`) with a single **Transfers**
(`/admin/transfers`, `icon: 'transfer'`).

The page is a **role-aware worklist with status tabs** plus a `+ New Request` button:

```
┌─ Transfers ───────────────────────────────┐
│  [+ New Request]                           │
│  [To Verify] [To Send] [To Receive]        │
│  [In Transit] [Done]                       │
├────────────────────────────────────────────┤
│  TR-0042  WH-A → Showroom      ● To Verify │
│  TR-0041  WH-B → WH-A          ● To Send   │
│  TR-0040  WH-C → Branch-2      ● To Receive│
└────────────────────────────────────────────┘
```

Tabs a role cannot act on are hidden. Rows are **location-scoped** using the existing `forUser($user)` /
`ScopesToUserLocation` machinery, so a warehouse sees only transfers where it is source or destination; the IA
and admin see the full org (subject to existing permission scoping).

Tab → who sees / acts:
- **To Verify** (`Submitted`): IA/admin — verify, trim, assign source, submit or reject.
- **To Send** (`Approved`): assigned source location + IA/admin — FIFO pick + dispatch.
- **To Receive / In Transit** (`In Transit`): destination location + IA/admin — receive.
- **Done** (`Received` / `Rejected`): everyone in scope — read-only + receipt.

## `+ New Request` (create)

A single modal with an intent selector (Pull / Offload / IA-demand — the last only shown to IA/admin):

- **Pull**: destination auto-set to the user's location (or chosen, for IA/admin); add products from catalog with
  needed quantities. `from_location_id` **left null**. Creates at `Submitted`.
- **Offload**: source auto-set to the user's location; add products from own on-hand stock; pick a **suggested**
  destination. Creates at `Submitted`.
- **IA-demand**: pick source + destination + products. Creates at **`Approved`** (skips verify).

Backend: relax the current `store` validation so `from_location_id` is **nullable** for pull requests, and record
the intent (see Data model).

## IA verify & assign step (the new gate)

For a `Submitted` transfer the IA panel allows, in one submit:

1. **Remove line items** and/or **reduce quantities** (extends today's `approve()` quantity-override support with
   line removal).
2. **Assign / confirm locations**: set `from_location_id` (pull) or adjust `to_location_id` (offload), backed by a
   **live per-warehouse availability view** — for each requested product, show which locations hold how much
   `available_quantity`, so the IA assigns a warehouse that can actually fulfill.
3. **Submit** → status `Approved`, notify the assigned source location.
   Or **Reject** with a reason → status `Rejected`.

Validation on submit: source and destination set and different; assigned source holds enough
`available_quantity` for each surviving line (warn, don't hard-block, if the IA knowingly under-assigns — partial
is allowed downstream).

## FIFO-guided picking (dispatch)

When the assigned source opens an `Approved` transfer, the system renders a **FIFO pick list** per line, derived
from existing data (no schema change):

- **Appliances (serialized):** list the individual **serial numbers** to pull, ordered oldest-batch-first via the
  serial's `receiving_id` → `inventory_batches.received_at`, each tagged with its **zone** (`zone_label`).
- **Consumables (bulk):** list **zone + quantity** per batch, oldest `received_at` first, until the line qty is met.

New read-only endpoint: `GET /api/transfers/{id}/pick-list` → per-line FIFO suggestions
`{ product_id, product_name, needed, suggestions: [{ serial_number?, zone_label, quantity, batch_id, received_at }] }`.

The picker **scans or manually enters** each unit. Per the confirmed decision, FIFO is **advisory** — the picker
may scan any valid in-stock unit (physical reality may differ from the suggestion); batch/FIFO accounting is
enforced **by count** by the existing `TransferService::recordDispatchScan` + `dispatch` (which already deducts
FIFO batches by `receiving_id` grouping and `deductFifo`). Confirm dispatch → `In Transit`; remainder auto-closes.

## Receive

Unchanged behavior, surfaced in the unified page. Destination scans or manually enters incoming units;
`TransferService::recordReceiveScan` verifies serialized scans against the shipment's in-transit serials;
`receive()` increments destination stock (both columns), reactivates serials at the destination, logs
shipped≠received discrepancies, and finalizes to `Received`. A receipt (existing `ist/receipt.blade.php` lineage)
is available on `Done`.

## Notifications

Reuse the existing realtime push (`RealtimeBroadcaster::transferChanged(fromLocationId, toLocationId, signal)` →
Echo bell + polling fallback + worklist badge counts):

- New pull/offload request submitted → notify **IA** ("New transfer request to verify").
- IA assigns source + submits → notify **assigned source** ("The IA assigned you a transfer for <destination>").
- Dispatched (In Transit) → notify **destination** ("Incoming transfer to receive").

Worklist tab badge counts come from the same status/scope query that drives the tabs.

## Removed / migrated

- **Delete** the admin express transfer (`TransferService::directOverride` + its route/page). One path for all.
- **Retire** routes `/admin/transfer-requests`, `/admin/transfers` (old), `/admin/ist` and their pages
  (`TransferRequestPage`, `StockTransferManagement`, the IST page). Fold all into `/admin/transfers`.
- **Consolidate** `TransferRequestController` + `ISTController` (+ the non-override parts of `TransferController`)
  into **one `TransferController`** that delegates to `TransferService`. Keep `TransferService` as the sole engine.
- Update the permission/nav bundle rows so the single `Transfers` nav is permissioned (per the
  all-nav-permissioned convention) and the retired paths are removed.

## Data model

Minimal, additive:

- `transfers.from_location_id` becomes **nullable** (pull requests have no source until the IA assigns).
- Add `transfers.intent` enum-ish string column: `pull` | `offload` | `demand` (drives the create modal, the
  product-picker source, and which location field the IA edits). Default backfill for existing rows: `demand`.
- No changes to `transfer_details`, `item_serial`, `inventory`, or `inventory_batches`.

All new columns via a single MySQL migration (schema is SQL-dump + targeted migrations; apply with `--path`).

## Testing

Feature tests run on cloned `airkingV5_test` with `DatabaseTransactions` (migrations are MySQL-only). Reuse the
existing `TransferTestCase` harness. Invariant preserved: `count(item_serial active) == inventory.quantity_on_hand`.

New / updated coverage:

1. **Create — pull** with null source succeeds at `Submitted`; **offload** stores source + suggested destination;
   **IA-demand** lands at `Approved`.
2. **IA verify/assign**: removing a line and reducing a qty persists; assigning `from_location_id` moves it to
   `Approved` and emits the assignment signal; reject moves to `Rejected`.
3. **Pick-list endpoint**: appliances return serials ordered by batch `received_at` with correct `zone_label`;
   consumables return zone+qty per batch oldest-first; totals equal line `quantity_requested`.
4. **Dispatch/receive** regression: existing `TransferService` tests still green (FIFO by count, serial integrity,
   partial auto-close, reverse).
5. **Scoping**: a warehouse sees only transfers where it is source/destination; tabs hide correctly by role.
6. **Removal**: express-transfer route gone; old three routes 404/redirect to `/admin/transfers`.

## Open confirmations folded into this spec (already agreed)

- Source choice: **IA assigns** in pull. ✔
- No self-service push; everything is an IA-gated request. ✔
- All three initiation intents supported. ✔
- FIFO: **suggest, enforce by count**. ✔
- Page: **role-aware worklist + tabs**. ✔
- Admin express transfer: **dropped**. ✔
- Pull picks from catalog; offload from own stock; offload destination is a suggestion the IA can change. ✔
