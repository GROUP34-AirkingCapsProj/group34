# Admin-Configurable Account-Settings Policy — Design

**Date:** 2026-06-29
**Status:** Approved (pending spec review)
**Branch:** backupV3
**Builds on:** `docs/superpowers/specs/2026-06-28-account-settings-design.md` (the self-service Account Settings feature this governs)

## Summary

Let an admin decide which fields ordinary users may edit on their own Account
Settings page. The admin manages one global policy in System Configuration; the
backend enforces it on every self-service write; the Account Settings page renders
locked fields as disabled with an explanatory note.

## Goals

- A single global policy governing four fields: **profile picture, email
  (local-part), phone, address**.
- Admin edits the policy in System Configuration (the repurposed "Profile
  Settings" tab).
- Backend enforces the policy on the `/me/*` self-service endpoints.
- The Account Settings page disables locked fields and explains why.

## Non-Goals

- No per-role or per-user policy (global only).
- Name (first/last) and password are **never** governed — always editable by
  everyone. Locking password change is a usability/security hazard and is out of
  scope.
- No change to admin User Management or the password-reset flow.

## Decisions (from brainstorming)

- **Scope:** one global policy for all non-admin users.
- **Admin bypass:** admins (role_id 1) always edit their own account; their
  *effective* policy is all-true regardless of the global setting.
- **Governed fields:** avatar, email, phone, address.
- **Locked-field UX:** show the field disabled/read-only with a "Managed by your
  administrator" note (do not hide it).
- **Placement:** repurpose the System Config "Profile Settings" tab into a "User
  Accounts" policy editor and remove the existing admin-own-profile stub (it is a
  non-functional duplicate of the new Account Settings page).

## Current State (verified)

- `SystemSetting` (`back-end/app/Models/SystemSetting.php`) is a `key`/`value`
  store with a JSON-aware `getValue`/`setValue` and typed accessors
  (`twoFactorAuthEnabled()`, archive-config blob, etc.). The `account_self_service_policy`
  blob follows the same shape as the existing `archive_config` blob.
- System settings endpoints live under `/system/*` (e.g.
  `SystemSessionSettingsController`, `CompanySettingsController`) and the
  admin-only ones are gated with `middleware('role:admin')` in `routes/api.php`.
- `AccountController` (`/me/*`) is the single self-service write path:
  `update` (name/phone/address), `uploadAvatar`/`deleteAvatar`, `updateEmail`,
  `updatePassword`. It already exposes `presentProfile()` used by `/me/profile`.
- `SystemConfiguration.jsx` "Profile Settings" tab currently edits the admin's
  own profile via local state and a stub `handleProfileSave` that only toasts —
  it does not persist. It duplicates the new `/admin/account` page.
- The frontend API client (`services/api.js`) groups endpoints into exported
  `*API` objects; admin pages use the shared `toast` helper.

## Architecture

### Storage

`SystemSetting` key `account_self_service_policy`, a JSON object:

```json
{ "avatar": true, "email": true, "phone": true, "address": true }
```

Default when unset: all `true` (fully permissive — nothing changes for existing
installs). New typed accessors on `SystemSetting`:

- `accountPolicy(): array` — returns the four booleans, merged over the all-true
  default so missing keys are safe.
- `setAccountPolicy(array $policy): void` — persists only the four known keys,
  cast to bool, via `setValue`.

### Effective policy + admin bypass

A helper computes the **effective** policy for a given user:

- Admin (role_id 1) → `['avatar'=>true,'email'=>true,'phone'=>true,'address'=>true]`
  (bypass).
- Everyone else → `SystemSetting::accountPolicy()`.

This lives as a small private helper in `AccountController` (and is the single
source of truth for both enforcement and what `/me/profile` reports).

### Backend — policy admin endpoints

`AccountPolicyController` (admin-only), mounted beside the other `/system/*`
admin settings in `routes/api.php` under `middleware('role:admin')`:

- `GET  /system/account-policy` → `{ avatar, email, phone, address }`.
- `PUT  /system/account-policy` → validates four booleans, persists, returns the
  saved policy.

### Backend — enforcement in `AccountController`

Using the effective policy for `$request->user()`:

- `uploadAvatar` / `deleteAvatar`: if `!policy['avatar']` → `403` with
  `"Profile photo changes are turned off by your administrator."`
- `updateEmail`: if `!policy['email']` → `403` with
  `"Email changes are turned off by your administrator."`
- `update` (profile): always applies `first_name`/`last_name`; includes `phone`
  in the update only if `policy['phone']`, and `address` only if
  `policy['address']`. Locked fields are never written — a UI-bypass attempt is a
  silent no-op, and name still saves in the same request.
- `updatePassword`: unaffected (never governed).
- All of the above are bypassed for admins (effective policy all-true).

`presentProfile()` adds a `policy` key (the effective policy) so `/me/profile`
carries it to the page.

### Frontend — System Configuration "User Accounts" tab

Repurpose the existing "Profile Settings" tab:

- Rename the tab to **"User Accounts"** (id `profile` → `accounts`; update the
  `TABS` entry, icon optional).
- Remove the admin-own-profile cards, the `profile`/`DEFAULT_PROFILE` state, the
  avatar `fileInputRef` block, `handleProfileChange`, and `handleProfileSave`.
- Add a single "User Account Editing" card with four labeled toggle rows
  (Profile picture, Email address, Phone number, Address), reusing the existing
  `sc-checkbox-row`/`sc-checkbox-label`/`sc-checkbox-desc` styles. A short lead
  line states the policy applies to all non-admin users; admins always manage
  their own account.
- Load via `accountPolicyAPI.get()` on mount; save via `accountPolicyAPI.update()`
  with a `toast.success('Account policy saved')`. Wire the existing footer Save
  button for this tab (replacing the current `toast.success('User settings saved!')`
  stub).

### Frontend — API client

New `accountPolicyAPI` in `services/api.js`:

- `get()` → `GET /system/account-policy`
- `update(policy)` → `PUT /system/account-policy`

### Frontend — Account Settings page enforcement

`AccountSettingsPage` reads `profile.policy`:

- **Profile photo:** when `!policy.avatar`, hide/disable the Upload and Remove
  buttons and show a "Managed by your administrator" note under the avatar.
- **Personal information:** phone and address inputs are `disabled` when their
  policy flag is false, each with the managed note; first/last name stay editable
  and "Save changes" still works (it only sends allowed fields — but the page may
  send all four; the backend ignores locked ones).
- **Sign-in email:** when `!policy.email`, disable the local-part and
  current-password inputs and the "Update email" button, with the managed note.
- A small reusable inline note element (`as-managed`) styled from `--ak-*` tokens
  carries the "Managed by your administrator" copy.

## Data Flow

1. Admin toggles fields in System Config → `PUT /system/account-policy` →
   `SystemSetting` JSON blob.
2. User opens Account Settings → `GET /me/profile` returns the effective `policy`
   → page disables locked controls + shows notes.
3. User submits a section → `AccountController` recomputes the effective policy
   and applies/blocks per field (403 for locked avatar/email; locked phone/address
   not written).

## Error Handling

- Locked avatar/email writes return `403` with a clear, system-voiced message.
- Locked phone/address are silently not applied (name still saves).
- Policy endpoints are admin-only; non-admins get `403` (role middleware).
- `PUT /system/account-policy` validates each field as `required|boolean`.

## Testing

Backend feature tests:

- `SystemSetting::accountPolicy()` returns all-true by default; `setAccountPolicy`
  round-trips and ignores unknown keys.
- `GET/PUT /system/account-policy` work for admin; non-admin gets 403 on both.
- Enforcement (non-admin user):
  - avatar locked → `POST /me/avatar` returns 403; `avatar_path` unchanged.
  - email locked → `PUT /me/email` returns 403; email unchanged.
  - phone locked → `PUT /me/profile` with a new phone + new name: name saves,
    phone unchanged.
  - address locked → same pattern, address unchanged.
- Admin bypass: with all fields locked, an admin can still update avatar/email/
  phone/address on their own account.
- `GET /me/profile` returns `policy` = all-true for an admin and the global
  policy for a non-admin.

Frontend is verified by a clean production build and a manual smoke test (toggle a
field off as admin, confirm it is disabled with the note on a non-admin's Account
Settings page).

## Open Questions

None — design approved.
