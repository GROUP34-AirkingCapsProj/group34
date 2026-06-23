# Realtime Notification Bell Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the header notification bell update instantly (sub-second) via Laravel Echo/Reverb instead of 60-second polling, and play a short sound on each genuinely-new notification.

**Architecture:** A lazy-singleton Laravel Echo client (pusher-js protocol → Reverb) authorizes private channels with the existing Sanctum bearer token. `AdminLayout` subscribes to the user's channel (`org` for centralized roles, `location.{id}` for scoped) and, on any signal event, immediately refetches `/layout-summary` and plays a chime when the unread badge increased. The existing 60s poll stays as a silent fallback.

**Tech Stack:** React (CRA), `laravel-echo`, `pusher-js`, Laravel Reverb (already configured), WebAudio API, Jest (`react-scripts test`).

---

## File Structure

- Create `frontend/src/admin/utils/realtimeChannel.js` — pure channel/event/badge helpers.
- Create `frontend/src/admin/utils/realtimeChannel.test.js` — unit tests for the helpers.
- Create `frontend/src/admin/utils/notifySound.js` — asset-free WebAudio chime.
- Create `frontend/src/admin/utils/echo.js` — lazy Echo singleton (`getEcho`/`disconnectEcho`).
- Modify `frontend/.env` — add `REACT_APP_REVERB_*` vars.
- Modify `frontend/package.json` (+ lockfile) — add `laravel-echo`, `pusher-js`.
- Modify `frontend/src/admin/context/AuthContext.jsx` — call `disconnectEcho()` on logout.
- Modify `frontend/src/admin/components/AdminLayout.jsx` — subscribe + sound wiring.

---

## Task 1: Install dependencies and add env

**Files:**
- Modify: `frontend/package.json` (+ `package-lock.json`)
- Modify: `frontend/.env`

- [ ] **Step 1: Install packages**

Run (in `frontend/`):
```bash
npm install laravel-echo pusher-js
```
Expected: both added to `dependencies`, lockfile updated, no errors.

- [ ] **Step 2: Add CRA-readable Reverb env vars**

Append to `frontend/.env` (the existing `VITE_REVERB_*` vars are invisible to CRA and stay as-is):
```
# --- Realtime (Reverb) — CRA needs REACT_APP_ prefix ---
REACT_APP_REVERB_APP_KEY=7uwsuly1xyugb7uffkoa
REACT_APP_REVERB_PORT=8080
REACT_APP_REVERB_SCHEME=http
# Optional override; defaults to the same host as REACT_APP_API_URL:
# REACT_APP_REVERB_HOST=10.0.0.72
```

- [ ] **Step 3: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/.env
git commit -m "build(realtime): add laravel-echo + pusher-js and Reverb client env"
```

---

## Task 2: Pure channel/event/badge helpers (TDD)

**Files:**
- Create: `frontend/src/admin/utils/realtimeChannel.js`
- Test: `frontend/src/admin/utils/realtimeChannel.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/realtimeChannel.test.js`:
```js
import { channelFor, badgeIncreased, REALTIME_EVENTS } from './realtimeChannel';

describe('channelFor', () => {
  test.each([1, 2, 5])('centralized role %i → org', (roleId) => {
    expect(channelFor({ role: { role_id: roleId }, location_id: 9 })).toBe('org');
  });

  test('scoped branch_manager (3) → location channel from enforced_location_id', () => {
    expect(channelFor({ role: { role_id: 3 }, enforced_location_id: 7 })).toBe('location.7');
  });

  test('scoped warehouse_personnel (4) → location channel from location_id', () => {
    expect(channelFor({ role: { role_id: 4 }, location_id: 4 })).toBe('location.4');
  });

  test('scoped user without a location → null', () => {
    expect(channelFor({ role: { role_id: 4 } })).toBeNull();
  });

  test('no user → null', () => {
    expect(channelFor(null)).toBeNull();
  });
});

describe('badgeIncreased', () => {
  test('higher than previous → true', () => {
    expect(badgeIncreased(2, 5)).toBe(true);
  });
  test('equal → false', () => {
    expect(badgeIncreased(5, 5)).toBe(false);
  });
  test('lower (marked read) → false', () => {
    expect(badgeIncreased(5, 1)).toBe(false);
  });
  test('first load from 0 → true when positive', () => {
    expect(badgeIncreased(0, 3)).toBe(true);
  });
});

describe('REALTIME_EVENTS', () => {
  test('lists the four dotted event names', () => {
    expect(REALTIME_EVENTS).toEqual([
      '.approval-queue.changed',
      '.purchase-order.changed',
      '.inventory.changed',
      '.transfer.changed',
    ]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (in `frontend/`): `CI=true npx react-scripts test src/admin/utils/realtimeChannel.test.js --watchAll=false`
Expected: FAIL — `Cannot find module './realtimeChannel'`.

- [ ] **Step 3: Write minimal implementation**

Create `frontend/src/admin/utils/realtimeChannel.js`:
```js
// Pure helpers for the realtime bell. Mirrors backend App\Support\ChannelAccess:
// centralized roles (admin=1, inventory_analyst=2, auditor=5) listen on `org`;
// scoped roles (branch_manager=3, warehouse_personnel=4) listen on their location.

const CENTRALIZED_ROLE_IDS = [1, 2, 5];

/** Event names broadcast by the backend (broadcastAs). Echo requires a leading
 *  dot to bind to custom event names. */
export const REALTIME_EVENTS = [
  '.approval-queue.changed',
  '.purchase-order.changed',
  '.inventory.changed',
  '.transfer.changed',
];

/**
 * Private channel name this user may subscribe to, or null if none.
 * Centralized → 'org'. Scoped → 'location.{id}' (null if no resolvable location).
 */
export function channelFor(user) {
  if (!user) return null;
  const roleId = Number(user?.role?.role_id ?? user?.role_id);
  if (CENTRALIZED_ROLE_IDS.includes(roleId)) return 'org';
  const locationId = user?.enforced_location_id ?? user?.location_id ?? null;
  if (locationId == null) return null;
  return `location.${locationId}`;
}

/** True when the new unread badge count is strictly greater than the previous. */
export function badgeIncreased(prev, next) {
  return Number(next) > Number(prev);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `CI=true npx react-scripts test src/admin/utils/realtimeChannel.test.js --watchAll=false`
Expected: PASS — all tests green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/realtimeChannel.js frontend/src/admin/utils/realtimeChannel.test.js
git commit -m "feat(realtime): pure channel + badge-increase helpers"
```

---

## Task 3: Notification sound

**Files:**
- Create: `frontend/src/admin/utils/notifySound.js`
- Test: `frontend/src/admin/utils/notifySound.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/notifySound.test.js`:
```js
import { playNotifySound } from './notifySound';

describe('playNotifySound', () => {
  test('never throws when WebAudio is unavailable', () => {
    const original = global.AudioContext;
    delete global.AudioContext;
    delete global.webkitAudioContext;
    expect(() => playNotifySound()).not.toThrow();
    global.AudioContext = original;
  });

  test('drives an oscillator when WebAudio is available', () => {
    const start = jest.fn();
    const stop = jest.fn();
    const connect = jest.fn();
    const ctx = {
      currentTime: 0,
      destination: {},
      createOscillator: () => ({ type: '', frequency: { value: 0 }, connect, start, stop }),
      createGain: () => ({ gain: { value: 0, setValueAtTime: jest.fn(), exponentialRampToValueAtTime: jest.fn() }, connect }),
      close: jest.fn(),
    };
    global.AudioContext = jest.fn(() => ctx);
    playNotifySound();
    expect(start).toHaveBeenCalled();
    expect(stop).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `CI=true npx react-scripts test src/admin/utils/notifySound.test.js --watchAll=false`
Expected: FAIL — `Cannot find module './notifySound'`.

- [ ] **Step 3: Write minimal implementation**

Create `frontend/src/admin/utils/notifySound.js`:
```js
// Short, asset-free notification chime via the WebAudio API. Best-effort:
// any failure (autoplay policy, unsupported browser) is swallowed silently.

/** Play a brief two-tone "ding". No-op if WebAudio is unavailable or blocked. */
export function playNotifySound() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const now = ctx.currentTime;

    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = 880; // A5
    gain.gain.value = 0.0001;
    osc.connect(gain);
    gain.connect(ctx.destination);

    // Quick attack, short exponential decay so it reads as a soft chime.
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.2, now + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);

    osc.start(now);
    osc.stop(now + 0.36);

    // Free the context shortly after the sound finishes.
    if (typeof ctx.close === 'function') {
      setTimeout(() => { try { ctx.close(); } catch (_) {} }, 600);
    }
  } catch (_) {
    // ignore — sound is non-essential
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `CI=true npx react-scripts test src/admin/utils/notifySound.test.js --watchAll=false`
Expected: PASS — both tests green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/notifySound.js frontend/src/admin/utils/notifySound.test.js
git commit -m "feat(realtime): asset-free WebAudio notification chime"
```

---

## Task 4: Echo singleton

**Files:**
- Create: `frontend/src/admin/utils/echo.js`

No unit test — this wires a real WebSocket library and is verified manually in Task 7. Keep it tiny and failure-safe.

- [ ] **Step 1: Write the implementation**

Create `frontend/src/admin/utils/echo.js`:
```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getApiBaseUrl, getToken } from '../services/api';

// pusher-js must be globally available for Laravel Echo's 'pusher' broadcaster.
window.Pusher = Pusher;

let echoInstance = null;

/** Host for the Reverb socket: explicit override, else the same host the API
 *  resolves to (so LAN clients use the machine IP, not 127.0.0.1). */
function resolveReverbHost() {
  const override = process.env.REACT_APP_REVERB_HOST && String(process.env.REACT_APP_REVERB_HOST).trim();
  if (override) return override;
  try {
    return new URL(getApiBaseUrl()).hostname;
  } catch (_) {
    return '127.0.0.1';
  }
}

/**
 * Lazily build and return the shared Echo instance, or null if it cannot be
 * created (missing key / construction error). Callers must tolerate null and
 * fall back to polling.
 */
export function getEcho() {
  if (echoInstance) return echoInstance;
  try {
    const key = process.env.REACT_APP_REVERB_APP_KEY && String(process.env.REACT_APP_REVERB_APP_KEY).trim();
    if (!key) return null;

    const host = resolveReverbHost();
    const port = Number(process.env.REACT_APP_REVERB_PORT || 8080);
    const scheme = String(process.env.REACT_APP_REVERB_SCHEME || 'http').toLowerCase();
    const forceTLS = scheme === 'https';

    echoInstance = new Echo({
      broadcaster: 'pusher',
      key,
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS,
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
      cluster: 'mt1', // ignored by Reverb but required by the pusher-js client
      authEndpoint: `${getApiBaseUrl()}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${getToken()}`,
          Accept: 'application/json',
        },
      },
    });
    return echoInstance;
  } catch (_) {
    echoInstance = null;
    return null;
  }
}

/** Tear down the socket (e.g. on logout) and clear the singleton. */
export function disconnectEcho() {
  try {
    if (echoInstance && typeof echoInstance.disconnect === 'function') {
      echoInstance.disconnect();
    }
  } catch (_) {
    // ignore
  } finally {
    echoInstance = null;
  }
}
```

- [ ] **Step 2: Verify the app still compiles**

Run (in `frontend/`): `CI=true npx react-scripts build`
Expected: build completes with no errors referencing `echo.js`.
(If the build is too slow in this environment, instead confirm there are no IDE/ESLint diagnostics on `frontend/src/admin/utils/echo.js`.)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/utils/echo.js
git commit -m "feat(realtime): lazy Laravel Echo singleton for Reverb"
```

---

## Task 5: Disconnect Echo on logout

**Files:**
- Modify: `frontend/src/admin/context/AuthContext.jsx`

- [ ] **Step 1: Import disconnectEcho**

At the top of `frontend/src/admin/context/AuthContext.jsx`, add to the existing imports:
```js
import { disconnectEcho } from '../utils/echo';
```

- [ ] **Step 2: Call it in clearSession**

In `clearSession` (around line 39, where it currently does `setPermissions(null)`), add a `disconnectEcho()` call so the socket is torn down on logout. Place it alongside the other teardown:
```js
    disconnectEcho();
```

- [ ] **Step 3: Verify no diagnostics**

Confirm there are no ESLint/IDE errors on `frontend/src/admin/context/AuthContext.jsx`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/context/AuthContext.jsx
git commit -m "feat(realtime): disconnect Echo socket on logout"
```

---

## Task 6: Subscribe in AdminLayout and play the sound

**Files:**
- Modify: `frontend/src/admin/components/AdminLayout.jsx`

Context: `AdminLayout` already has `syncBadges()` inside a `useEffect` that polls every 60s and sets `unreadNotifications` from `d.badge_count`. We add (a) a ref that remembers the last badge count, (b) a reusable refresh function, and (c) a second `useEffect` that subscribes to the realtime channel.

- [ ] **Step 1: Add imports**

In the import block of `frontend/src/admin/components/AdminLayout.jsx`, add:
```js
import { getEcho } from '../utils/echo';
import { channelFor, REALTIME_EVENTS, badgeIncreased } from '../utils/realtimeChannel';
import { playNotifySound } from '../utils/notifySound';
```
Also ensure `useRef` is imported from React (add it to the existing `react` import if missing).

- [ ] **Step 2: Add a ref to track the previous badge count**

Near the existing `useState` declarations (around line 30, after `const [unreadNotifications, setUnreadNotifications] = useState(0);`), add:
```js
  const prevBadgeRef = useRef(0);
```

- [ ] **Step 3: Record the badge count inside the existing syncBadges**

In the existing badge `useEffect` (around line 79), immediately after `setUnreadNotifications(Number(d.badge_count ?? 0));`, add:
```js
        prevBadgeRef.current = Number(d.badge_count ?? 0);
```
This keeps the ref current for the polling path (which must NOT play a sound).

- [ ] **Step 4: Add the realtime subscription effect**

Add this new `useEffect` directly after the existing badge-polling `useEffect` (after its closing `}, []);` around line 96):
```js
  // Realtime: push updates to the bell instantly via Echo/Reverb. Falls back to
  // the 60s poll above when Reverb is unreachable. Only the push path plays sound.
  useEffect(() => {
    if (!can('notification_panel', 'read')) return;
    const channelName = channelFor(user);
    if (!channelName) return;
    const echo = getEcho();
    if (!echo) return;

    let cancelled = false;

    const onEvent = async () => {
      try {
        const res = await layoutAPI.getSummary();
        const d = res?.data ?? {};
        if (cancelled) return;
        const next = Number(d.badge_count ?? 0);
        const grew = badgeIncreased(prevBadgeRef.current, next);
        setUnreadNotifications(next);
        setPendingApprovalCount(Number(d.total_pending ?? 0));
        setAwaitingIstCount(Number(d.awaiting_fulfillment ?? 0) + Number(d.awaiting_receipt ?? 0));
        prevBadgeRef.current = next;
        if (grew) playNotifySound();
      } catch (_) {
        // ignore — poll will reconcile
      }
    };

    let channel;
    try {
      channel = echo.private(channelName);
      REALTIME_EVENTS.forEach((evt) => channel.listen(evt, onEvent));
    } catch (_) {
      return;
    }

    return () => {
      cancelled = true;
      try {
        REALTIME_EVENTS.forEach((evt) => channel && channel.stopListening(evt));
        echo.leave(channelName);
      } catch (_) {
        // ignore
      }
    };
  }, [user, can]);
```

- [ ] **Step 5: Verify no diagnostics**

Confirm there are no ESLint/IDE errors on `frontend/src/admin/components/AdminLayout.jsx` (especially no unused-import or missing-`useRef` warnings).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/AdminLayout.jsx
git commit -m "feat(realtime): instant bell updates via Echo subscription + chime"
```

---

## Task 7: Manual end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Start Reverb (LAN-reachable)**

Run (in `back-end/`): `php artisan reverb:start --host=0.0.0.0 --port=8080`
Expected: "Starting server on 0.0.0.0:8080".

- [ ] **Step 2: Start the frontend**

Run (in `frontend/`): `npm start` (or `npm run start:lan` for LAN devices).

- [ ] **Step 3: Verify the socket connects**

Log in as a Warehouse Personnel (scoped) user. In the browser devtools Network → WS tab, confirm a WebSocket to the Reverb host/port is open and a successful POST to `/api/broadcasting/auth` for `private-location.{id}`.

- [ ] **Step 4: Trigger an event from another session**

In a second browser/session, as a user at the SAME location, create a Purchase Request / adjustment / transfer (any wired trigger). 
Expected: within ~1 second, the first user's bell badge increments and the chime plays — no 60s wait.

- [ ] **Step 5: Verify centralized role**

Repeat as an admin (centralized): confirm subscription to `private-org` and that events from any location update the badge instantly.

- [ ] **Step 6: Verify graceful fallback**

Stop Reverb (Ctrl-C). Confirm the app keeps working and the badge still updates within 60s via polling, with no console errors blocking the UI.

---

## Self-Review Notes

- **Spec coverage:** deps+env (Task 1), `realtimeChannel.js`/`channelFor`/`EVENTS` (Task 2), `notifySound.js` (Task 3), `echo.js` getEcho/disconnectEcho (Task 4), logout teardown (Task 5), AdminLayout subscribe + sound-on-increase + polling fallback (Task 6), runtime requirement + manual tests (Task 7). All spec sections covered.
- **Sound-on-new only:** `badgeIncreased(prev, next)` gates the chime; the polling path updates `prevBadgeRef` without sounding, so only true increases via push chime.
- **Naming consistency:** `getEcho`/`disconnectEcho`, `channelFor`, `REALTIME_EVENTS`, `badgeIncreased`, `playNotifySound`, `prevBadgeRef` used identically across tasks.
