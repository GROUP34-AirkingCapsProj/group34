# Self-Service Account Settings — Design

**Date:** 2026-06-28
**Status:** Approved (pending spec review)
**Branch:** backupV3

## Summary

Give every authenticated user a self-service Account Settings page to manage their
own profile photo, personal/contact details, login email, and password. This is
distinct from the existing admin User Management flow (`UserController` /
`/users/{user}` routes), which stays permission-gated. Self-service endpoints act
only on the currently authenticated user and require authentication only — no
`user_account_management` permission.

## Goals

- Upload / replace / remove a profile picture (first file-upload feature in the app).
- Edit own personal/contact info: first name, last name, phone, address.
- Change own login email — **local-part only**; the `@airking.com` domain is fixed
  and structurally unchangeable.
- Change own password.
- Sensitive changes (email, password) require re-entering the current password.

## Non-Goals

- No OTP / email-verification step for changes (explicitly deferred).
- No changes to admin User Management authorization or routes.
- No username editing in this iteration.
- No job-title / department fields (deferred).

## Current State (verified)

- `users` table has: `first_name, last_name, email, username, password_hash, phone,
  role_id, location_id, status_id`. No avatar or address column.
- `UserController::changePassword` and `update` exist but sit behind
  `permission:user_account_management,update` middleware that ordinary users lack.
- `update` currently allows free email changes with no domain restriction.
- A `/me/permissions` route already establishes the "any authenticated user, own
  data" pattern — the new endpoints follow it.
- No file-upload pattern exists anywhere yet; this establishes one.
- Frontend `apiRequest()` (`frontend/src/admin/services/api.js`) hardcodes
  `Content-Type: application/json`; FormData uploads need a carve-out so the browser
  can set the multipart boundary.
- Sidebar (`frontend/src/admin/components/Sidebar.jsx`) already renders an
  initials avatar via `avatarColor`; a real photo enhances this.

## Architecture

### Approach (chosen: A)

Dedicated self-service `AccountController` + `/me/*` routes, with a new
`AccountSettingsPage.jsx`. Keeps self-service authorization ("always my own
account") cleanly separate from the already-busy permission-gated admin
`UserController`. Rejected alternatives: extending `UserController` with a
self-guard (B) and minimal reuse (C) — both inherit the admin permission
middleware that self-users don't have, forcing tangled self-vs-admin branching.

### Database

One migration adding two nullable columns to `users`:

- `avatar_path VARCHAR(255) NULL` — relative path on the `public` disk
  (e.g. `avatars/<user_id>-<random>.jpg`).
- `address VARCHAR(255) NULL`.

Add both to the `User` model `$fillable`. (Schema is SQL-dump-based per project
convention, but new columns ship as a single migration applied via
`artisan migrate --path`.)

### Storage

- Laravel `public` disk, `avatars/` directory, served through the `storage`
  symlink (`php artisan storage:link` must be present).
- Validation: `image` mime, max ~2 MB.
- On replace/remove: delete the previous file from disk.
- API responses expose a computed `avatar_url` (absolute URL via
  `Storage::disk('public')->url(...)`, or `null` when unset).

### Backend — `AccountController`

All methods act on `$request->user()`; routes mounted under the authenticated
group, no extra permission middleware.

| Method & route          | Body                                   | Auth re-check        |
|-------------------------|----------------------------------------|----------------------|
| `GET /me/profile`       | —                                      | session only         |
| `PUT /me/profile`       | `first_name, last_name, phone, address`| session only         |
| `POST /me/avatar`       | multipart `avatar` file                | session only         |
| `DELETE /me/avatar`     | —                                      | session only         |
| `PUT /me/email`         | `email_local`, `current_password`      | current password     |
| `PUT /me/password`      | `current_password`, `new_password`, `new_password_confirmation` | current password |

- `GET /me/profile` returns the profile incl. `avatar_url` and the email split
  into `email_local` (substring before `@`) plus the fixed domain for display.
- **Email rule:** the server composes `email = <email_local>@airking.com`. The
  domain is never read from user input, so `@airking.com` cannot be changed.
  `email_local` is validated (non-empty, valid local-part chars, length) and the
  composed address checked for uniqueness against other users.
- **Password rule:** reuse the existing
  `Password::min(8)->mixedCase()->numbers()->symbols()` rule; verify
  `current_password` via `Hash::check` before applying.
- Both sensitive endpoints return 422 on incorrect `current_password`.

### Frontend — `AccountSettingsPage.jsx`

Built on the shared `ak-` design system. Four sections:

1. **Profile photo** — preview (photo or initials fallback), upload, remove.
2. **Personal info** — first name, last name, phone, address. Save with no
   password.
3. **Email** — local-part text field with a locked, non-editable `@airking.com`
   suffix; requires current-password confirmation to save.
4. **Password** — current, new, confirm.

- Entry point: clicking the user block in the sidebar navigates here.
- On successful profile/email/avatar save, update the cached `user` object in
  localStorage so the sidebar reflects the new name/photo immediately.
- Sidebar avatar shows the uploaded photo when `avatar_url` is present, else the
  existing initials avatar.
- `apiRequest` gains an upload mode (or a sibling helper) that omits the JSON
  `Content-Type` for FormData requests.

## Error Handling

- Validation failures return 422 with field errors (existing `ApiResponse`/
  `validationError` convention).
- Incorrect `current_password` → 422 with a clear message, no change applied.
- Avatar upload: non-image or oversized → 422; old file is only deleted after the
  new file is successfully stored.
- Email uniqueness collision → 422 on `email_local`.

## Testing

Feature tests (one authenticated user acting on self):

- `PUT /me/profile` persists name/phone/address.
- `POST /me/avatar` stores an image and sets `avatar_path`; rejects non-image and
  oversized files; replacing an avatar deletes the old file.
- `DELETE /me/avatar` clears `avatar_path` and removes the file.
- `PUT /me/email` always forces the `@airking.com` domain (even if a different
  domain is somehow submitted), enforces uniqueness, and rejects without the
  correct `current_password`.
- `PUT /me/password` changes the password only with the correct `current_password`
  and a rule-compliant new password.
- A user cannot modify another user's account through these endpoints (they only
  ever act on `$request->user()`).

## Open Questions

None — design approved.
