# Realtime Notification Bell Client — Design

**Date:** 2026-06-24
**Branch:** backupV3
**Status:** Approved (pending spec review)

## Problem

The header notification bell (`AdminLayout.jsx`) updates only via a 60-second
poll of `/api/layout-summary`. Users wait many seconds to see a new alert. The
backend already broadcasts realtime signal events over Laravel Reverb
(`org` + `location.{id}` private channels, `ShouldBroadcastNow`), but the
frontend has **no** WebSocket client, so nothing consumes them.

Goal: the bell badge updates **instantly** (sub-second) when a relevant event
occurs, and a short sound plays on each genuinely-new notification. Scope is the
header bell only — other pages keep their current refetch behaviour.

## Why it was "slow"

The only live mechanism today is the 60s poll. There is no push consumer. Once
Echo consumes the `ShouldBroadcastNow` events, latency becomes: WebSocket push
(ms) + one `/layout-summary` refetch (ms) ≈ sub-second. The poll is demoted to a
silent fallback for when Reverb is not running.

## Architecture

Standard Laravel Echo + pusher-js client pointed at Reverb. Lazy singleton so a
single socket serves the whole app. Private-channel auth reuses the existing
Sanctum bearer token against `/api/broadcasting/auth`.

```
Action commits ──► RealtimeBroadcaster ──► Reverb (private-org / private-location.{id})
                                                   │  WebSocket push (ms)
                                                   ▼
   AdminLayout useEffect ◄── Echo .private(channel).listen('.<event>')
        │ on event (immediate)
        ├─ syncBadges()  → GET /layout-summary  → setUnreadNotifications(badge_count)
        └─ if badge_count increased → playNotifySound()
```

## Components

### 1. Dependencies & env
- `npm i laravel-echo pusher-js` in `frontend/`.
- New CRA-readable env (the existing `VITE_REVERB_*` vars are invisible to CRA):
  - `REACT_APP_REVERB_APP_KEY` — Reverb app key.
  - `REACT_APP_REVERB_HOST` — optional override. **Default:** the host parsed
    from `REACT_APP_API_URL` (so LAN clients connect to `10.0.0.72`, not
    `127.0.0.1`), matching the app's existing API-host detection.
  - `REACT_APP_REVERB_PORT` — default `8080`.
  - `REACT_APP_REVERB_SCHEME` — `http`|`https`, default `http`; controls
    `forceTLS` and `wss` vs `ws`.

### 2. `frontend/src/admin/utils/echo.js`
Lazy singleton.
- `getEcho()` — constructs (once) and returns an Echo instance:
  `broadcaster: 'pusher'`, `key`, `wsHost`, `wsPort`, `wssPort`, `forceTLS`,
  `enabledTransports: ['ws','wss']`, `authEndpoint: <API base>/broadcasting/auth`,
  `auth.headers.Authorization: 'Bearer ' + getToken()`. Returns `null` on
  failure (missing key, construction error) — caller silently keeps polling.
- `disconnectEcho()` — disconnects and clears the singleton. Called from
  `AuthContext.clearSession` on logout, mirroring the prior Echo lifecycle.

### 3. `frontend/src/admin/utils/realtimeChannel.js`
Pure, unit-testable.
- `channelFor(user)` → `'org'` when `role_id ∈ {1,2,5}` (centralized:
  admin/IA/auditor); else `'location.' + locationId` for scoped roles; `null`
  when a scoped user has no resolvable location. Mirrors backend `ChannelAccess`.
- `EVENTS` — the four event names to listen for (leading dot required by Echo
  for custom `broadcastAs` names): `.approval-queue.changed`,
  `.purchase-order.changed`, `.inventory.changed`, `.transfer.changed`.

### 4. `frontend/src/admin/utils/notifySound.js`
- `playNotifySound()` — short asset-free WebAudio chime via a lazily-created
  `AudioContext`, wrapped in try/catch (autoplay-policy / unsupported → no-op).
  No bundled binary; can be swapped for an mp3 later.

### 5. `AdminLayout.jsx` wiring
- Existing `syncBadges()` is reused; refactor it so the latest `badge_count` is
  comparable across calls (store previous in a ref).
- New `useEffect` keyed on the resolved user + `can('notification_panel','read')`:
  - Resolve `channel = channelFor(user)`. If `null` or no Echo, do nothing
    (poll remains).
  - `echo.private(channel)` and `.listen(eventName, handler)` for each of
    `EVENTS`. Handler fires **immediately** (no long debounce; optional ≤150 ms
    coalescing of bursts) → `syncBadges()`; when the new `badge_count` exceeds
    the previous, `playNotifySound()`.
  - Cleanup: `.stopListening(...)` / `echo.leave(channel)` on unmount or when
    the channel changes.

### 6. Polling fallback
The 60s `setInterval` poll is unchanged and remains as graceful degradation when
Reverb is down. It does **not** play the sound (push path owns the sound).

## Error handling / degradation
- Echo unavailable or auth fails → no socket; bell still polls.
- Sound failure → swallowed.
- Reverb not running → connection error swallowed; poll covers correctness.

## Runtime requirement
Reverb must run and be LAN-reachable:
`php artisan reverb:start --host=0.0.0.0 --port=8080`.

## Testing
- Unit: `channelFor(user)` across all five roles + missing-location case.
- Unit: "should play sound" decision (new badge_count > previous → true; equal or
  lower → false).
- Manual: log in as scoped (WP) and centralized (admin) users, trigger an action
  from another session, confirm the badge updates sub-second and the chime plays.

## Out of scope
- Live refresh of Alerts page / Approval Queue / inventory lists.
- A persistent per-user mute toggle (can be added later).
- Replacing polling entirely.
