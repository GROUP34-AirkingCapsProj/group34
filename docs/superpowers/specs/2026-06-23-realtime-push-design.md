# Real-Time Push (Reverb + Echo) — Design Spec

**Date:** 2026-06-23
**Branch:** backupV3
**Status:** Approved design, pending spec review

## 1. Problem

The app is not real-time. Most screens fetch data only on navigation/mount, so a
change another user makes is invisible until you refresh or navigate back. A few
screens poll (header badges every 60s via `/api/layout-summary`; Approval Queue every
15s), but the rest are "refresh-to-see."

There is **no existing real-time infrastructure**: `composer.json` has no
`laravel/reverb`; there is no `config/broadcasting.php`/`config/reverb.php`, no
`app/Events/`, no `routes/channels.php`; the frontend has no `laravel-echo`/`pusher-js`.
(A Reverb-based notifications feature existed earlier but was removed on 2026-06-15.)
This is therefore a from-scratch build on both ends.

## 2. Goal / Non-Goals

**Goal:** Push backend changes to the UI so the notification badges and four key list
screens update live (no manual refresh): **Approval Queue, Inventory list,
Transfers/IST, Purchase Orders list**.

**Approach (decided):** "Signal + refetch." The backend broadcasts tiny *signal* events
("something changed at location X"); a subscribed screen responds by re-running the
**same fetch it already does today**, debounced. No payload merging, no client-side list
patching. The server stays authoritative and all existing data/scoping logic is reused
unchanged.

**Environment (decided):** Dev + LAN only. One extra persistent process
(`php artisan reverb:start`) is acceptable. No cloud/production target. No queue worker
(events broadcast synchronously).

**Non-Goals:**
- In-place client-side patching of list rows from event payloads (Approach B/C — rejected).
- Live updates on screens beyond the four listed.
- A persisted notifications table / notification center (out of scope; this is live
  refresh, not stored notifications).
- Production/queue/Redis scaling. Reverb defaults are fine for LAN.

## 3. Architecture

**Transport:** `laravel/reverb` (backend WebSocket server, Pusher protocol) +
`laravel-echo` + `pusher-js` (frontend). Reverb runs as its own process on a WS port
(default 8080), alongside Apache.

**Broadcast mode:** All events implement `ShouldBroadcastNow` → broadcast happens
synchronously within the request. No queue worker required.

**Channel model — mirrors the existing location-scope model** (`User::isCentralized()`
roles {1,2,5}; `User::scopedLocationId()` roles {3,4}, established in the unified-UI
work):
- `org` (private) — centralized roles only (admin=1, inventory_analyst=2, auditor=5),
  who see all locations.
- `location.{id}` (private) — a scoped user may subscribe only to their own
  `location_id`; centralized users may subscribe to any.

**Data flow (one round trip per change):**
1. A controller action mutates data (e.g. PO approved at location 3).
2. It fires a signal event carrying a tiny payload `{ type, location_id, at }` (transfers
   carry `from`/`to` instead of a single `location_id`).
3. The event's `broadcastOn()` returns `org` **and** the relevant `location.{id}`
   channel(s); transfers return `org` + `location.{from}` + `location.{to}`.
4. Subscribed clients receive the signal and **debounced-refetch** the existing endpoint
   for that screen. No merging.

## 4. Backend

### 4.1 Install & config
- `composer require laravel/reverb`; `php artisan install:broadcasting`.
- Set `BROADCAST_CONNECTION=reverb` and `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME` in
  `.env` (gitignored; document in `.env.example`).
- Ensure the broadcasting auth route is reachable as `/api/broadcasting/auth` and guarded
  by `auth:sanctum` (Bearer-token friendly). If `install:broadcasting` registers it on
  the `web` guard, re-register it inside the `auth:sanctum` API group.

### 4.2 Events (`app/Events/`)
Four signal events, each `implements ShouldBroadcastNow`, each a small immutable payload
and a `broadcastOn()` returning private channels. `broadcastAs()` gives a stable event
name the frontend listens for.

| Event | Payload | Channels | `broadcastAs()` |
|---|---|---|---|
| `ApprovalQueueChanged` | `{type, location_id, at}` | `org`, `location.{id}` | `approval-queue.changed` |
| `InventoryChanged` | `{type, location_id, at}` | `org`, `location.{id}` | `inventory.changed` |
| `TransferChanged` | `{type, from, to, at}` | `org`, `location.{from}`, `location.{to}` | `transfer.changed` |
| `PurchaseOrderChanged` | `{type, location_id, at}` | `org`, `location.{id}` | `purchase-order.changed` |

`type` is a short string for debugging/future filtering (e.g. `po_approved`,
`stock_received`); the client does not branch on it in v1 — any event on a screen's
channel triggers that screen's refetch.

### 4.3 Dispatcher (`app/Support/RealtimeBroadcaster.php`)
A thin helper with one static method per event (e.g. `approvalQueueChanged(int $locationId)`,
`inventoryChanged(int $locationId)`, `transferChanged(int $from, int $to)`,
`purchaseOrderChanged(int $locationId)`). Each wraps the `broadcast(new …Event(...))`
call in try/catch that logs and swallows failures, so a Reverb outage never breaks the
underlying action (same safety posture as the removed system). Controllers call the
helper in one line.

### 4.4 Trigger points (existing controller actions)
Fire the matching helper after the data mutation commits. Exact methods/lines pinned in
the implementation plan; by domain:
- **Approval queue / PO:** `PurchaseOrderController` store + update (status transitions);
  `PurchaseRequestController` store/approve/reject/convert; `PendingProductController`
  approve. (PO status changes fire both `purchaseOrderChanged` and, when they affect the
  pending queue, `approvalQueueChanged`.)
- **Inventory:** `ReceivingController` (po-scan / store), `IssuanceController` store +
  issuance-scan complete, `AdjustmentController` store, `BarcodeAdjustmentController`
  submit, and the transfer-receive path where stock lands.
- **Transfers / IST:** `TransferRequestController` store/approve/reject; `ISTController`
  create / confirmDispatch / receive (use `transferChanged(from, to)`).

A trigger may fire more than one event (e.g. an IST receive changes inventory *and*
transfer state → `inventoryChanged` + `transferChanged`). Debouncing on the client makes
the double-fire harmless.

### 4.5 Channel auth (`routes/channels.php`)
- `org` → granted iff `$user->isCentralized()`.
- `location.{id}` → granted iff `$user->isCentralized() || $user->scopedLocationId() === (int) $id`.

## 5. Frontend

### 5.1 Echo client — `src/admin/utils/echo.js`
- Lazy singleton; broadcaster `reverb` (pusher-js under the hood).
- **Custom authorizer** that POSTs channel/socket id to `/api/broadcasting/auth` with
  header `Authorization: Bearer <token>` (token read the same way `api.js` does), because
  auth is token-in-localStorage, not cookies.
- **Dynamic host resolution** mirroring `getApiBaseUrl()`: loopback →
  `REVERB_HOST`/`airking.local`; private-LAN host → the page's `window.location.hostname`;
  port/scheme from `REACT_APP_REVERB_PORT` / `REACT_APP_REVERB_SCHEME`. So a phone on
  Wi-Fi connects to the dev machine's LAN IP, same logic the API base already uses.
- Exports `getEcho()` (init-or-return) and `disconnectEcho()` (called from
  `AuthContext.clearSession`, alongside the existing permission-cache clear).

### 5.2 Subscription hook — `src/admin/hooks/useRealtime.js`
- Signature: `useRealtime(handlers)` where `handlers` maps `broadcastAs` name → callback.
- Determines the channel from the current user: `org` if `isCentralized`, else
  `location.{scopedLocationId}` (no subscription if a scoped user has no location).
- Subscribes via `getEcho().private(channel).listen('.'+name, cb)` for each handler;
  callbacks are wrapped in a shared **debounce (~400 ms)** so an event burst triggers one
  refetch. Cleans up listeners/subscription on unmount or user change.
- If Echo can't initialize/connect, the hook is inert (no throw) — screens fall back to
  their polling.

### 5.3 Screen wiring
Each screen passes its **existing fetch function** as the handler callback:
- Approval Queue → `approval-queue.changed` (+ `purchase-order.changed`) → its current PO/PR fetch.
- Inventory list → `inventory.changed` → its current inventory fetch.
- Transfers/IST → `transfer.changed` (+ `inventory.changed` where the screen shows stock) → its current fetch.
- Purchase Orders list → `purchase-order.changed` → its current fetch.
- AdminLayout (badges) → all four events → refetch `/api/layout-summary` (debounced).

## 6. Graceful degradation & LAN ops

- **Existing polls stay as a safety net** (unchanged, or relaxed since real-time now
  covers the gap). With `reverb:start` not running, Echo fails to connect and screens
  still update via polling — real-time is a pure accelerator, never a hard dependency.
- **LAN:** Reverb binds `0.0.0.0:8080`; open TCP 8080 in Windows Firewall (Private).
- **Run command** documented next to the Apache notes: `php artisan reverb:start`
  (add to the `composer dev` concurrently script as an optional convenience).
- `.env` holds `REVERB_*`; `frontend/.env` (gitignored) holds `REACT_APP_REVERB_HOST/PORT/SCHEME`.

## 7. Testing

- **Backend:**
  - Event dispatch: with `Event::fake()`, assert each trigger action dispatches the
    correct event, and assert each event's `broadcastOn()` returns the expected channel
    names (incl. transfer's two location channels + `org`).
  - Channel auth: `routes/channels.php` callbacks — scoped user denied another location's
    channel and `org`; allowed own `location.{id}`; centralized allowed `org` and any
    `location.{id}`.
  - Safety: dispatcher swallows/logs a broadcast exception without bubbling (assert the
    triggering action still succeeds when broadcasting throws).
- **Frontend:**
  - Authorizer builds the `Authorization: Bearer` header and posts to the auth endpoint.
  - Host resolver returns the right WS host/port for loopback vs LAN inputs.
  - `useRealtime` debounce: N rapid events → callback invoked once.

## 8. Risks & mitigations

- **Bearer-token channel auth** is the main non-boilerplate piece (Echo defaults to
  cookie/CSRF). Mitigation: explicit custom authorizer; backend auth route on
  `auth:sanctum`; covered by a frontend authorizer test + a manual subscribe check.
- **LAN host/port mismatch** (phones) → WS won't connect. Mitigation: reuse the proven
  `getApiBaseUrl()` resolution pattern; firewall note; graceful fallback to polling.
- **Event storms** (bulk scan/receive loops firing many events) → many refetches.
  Mitigation: client debounce; fire domain events once per request/transaction commit,
  not per row, where practical.
- **Forgotten process** (`reverb:start` not running) → no live updates. Mitigation:
  graceful degradation to existing polls; documented run command.

## 9. Out of scope / follow-ups

- Persisted notification center / history.
- Presence channels ("who's online"), typing indicators, etc.
- Production deployment (managed Reverb service, TLS/WSS, Redis scaling).
- Live updates on screens beyond the four named.
