# Drop `user_alert_settings`, move read-state to `users.alerts_last_seen_at`

**Date:** 2026-06-28
**Branch:** backupV3
**Status:** Approved design

## Problem

The `user_alert_settings` table holds exactly one piece of state per user:
`pending_approvals_last_seen_at` — the timestamp of the last time that user clicked
"Mark all read" on the alerts bell. The unread badge counts pending items created
after this timestamp.

The table looks abandoned (one row per user, most values `NULL`, rarely updated),
but it is functionally live: it backs the unread badge and the "Mark all read"
action on every admin page load. We want to remove the standalone table without
changing any user-visible behavior.

## Decision

Replace the table with a single nullable column on `users`:
`alerts_last_seen_at TIMESTAMP NULL`.

A single timestamp per user is user-scoped state and belongs on the user record.
This removes one table, one model, and the get-or-create indirection.

### Alternatives considered

- **Generic `preferences_json` column on users** — more future-proof, but there is
  exactly one preference and no others planned. YAGNI.
- **Cache / session store** — would not persist read-state reliably across logout
  or multiple devices. Read-state must be durable.

## Data migration

Backfill is required (approved): preserve existing read-state so no user sees
previously-read items resurface as unread.

Current data (dev DB): 7 rows, of which only `user_id = 2` has a non-null
timestamp (`2026-06-23`). The rest are `NULL`, equivalent to "never marked read."

Migration order:
1. Add `users.alerts_last_seen_at TIMESTAMP NULL`.
2. Backfill: `UPDATE users u JOIN user_alert_settings s ON s.user_id = u.user_id
   SET u.alerts_last_seen_at = s.pending_approvals_last_seen_at;`
   (join on the real PK `users.user_id`).
3. Drop `user_alert_settings`.

This repo is SQL-dump based (not migration-driven) per project notes, so:
- The new migration is the record of the change.
- `airking_cleans.sql` must be updated to add the column to `users` and remove the
  `user_alert_settings` table definition + its seed data.

## Code changes

### `app/Models/User.php`
- Add `'alerts_last_seen_at'` to `$fillable`.
- Add `'alerts_last_seen_at' => 'datetime'` to `$casts`.

### `app/Http/Controllers/Api/AlertSettingsController.php`
- `index()`: replace
  `$settings = UserAlertSettings::getOrCreateForUser($userId);`
  `$lastSeen = $settings?->pending_approvals_last_seen_at;`
  with `$lastSeen = $user->alerts_last_seen_at;` (the `$user` is already resolved).
  The existing `if ($lastSeen)` branch already handles the null case correctly.
- In the JSON response, change
  `'pending_approvals_last_seen_at' => $settings?->...`
  to read from `$user->alerts_last_seen_at?->toIso8601String()`.
  **Keep the response key `pending_approvals_last_seen_at`** so the frontend
  contract is unchanged.
- `markAllRead()`: replace the get-or-create + `markPendingApprovalsSeen()` with
  `$user = $request->user(); $user->alerts_last_seen_at = now(); $user->save();`
  (switch the method signature to accept `Request $request` to get the user, or use
  `Auth::user()`). Return the new timestamp under the same `pending_approvals_last_seen_at`
  key.
- Remove `use App\Models\UserAlertSettings;`.

### Delete
- `app/Models/UserAlertSettings.php`.

## What does NOT change

- `DashboardController::layoutSummary()` — calls `index()`, keeps working.
- Routes `/alerts/settings` and `/alerts/mark-all-read` — unchanged.
- Frontend `frontend/src/admin/services/api.js` and `AlertsNotices.jsx` — unchanged
  (response shape preserved, including the `pending_approvals_last_seen_at` key).
- Badge math and unread logic — identical.

## Testing / verification

- After migration: `SHOW COLUMNS FROM users` includes `alerts_last_seen_at`;
  `user_alert_settings` no longer exists.
- Backfill check: `user_id = 2` has `alerts_last_seen_at = 2026-06-23 16:51:39`.
- `GET /alerts/settings` returns the same shape as before, with
  `pending_approvals_last_seen_at` populated for user 2.
- `POST /alerts/mark-all-read` sets `users.alerts_last_seen_at = now()` and the
  badge unread count drops to 0.
- Load an admin page (exercises `DashboardController::layoutSummary`) — no errors.

## Out of scope

- Any change to badge severity, alert types, or scoping logic.
- Introducing a general per-user preferences store.
