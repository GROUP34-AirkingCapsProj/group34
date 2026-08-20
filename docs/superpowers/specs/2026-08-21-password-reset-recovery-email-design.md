# Password Reset via Recovery Email + Resend Delivery

Date: 2026-08-21
Status: Approved for planning

## Problem

Employees log in with a company address (`user@airking.com`). When they forget
their password, the reset code is mailed to that same company inbox — which they
may not be able to reach, and which is the account they are locked out of.

Two further gaps:

- `MAIL_MAILER=log`. Reset codes are written to `storage/logs/laravel.log` and
  never delivered. The forgot-password flow does not work in production today.
- Accounts created by an admin keep the admin-typed password indefinitely. There
  is no mechanism forcing the employee to replace it.

## What already exists

This feature builds on working code. Establishing that up front, because the
original request assumed a greenfield build:

- `ForgotPasswordOtpController` — a complete OTP flow: 6-digit code, bcrypt
  hashed, 10-minute TTL, single active challenge per email, single-use 64-char
  `reset_token` issued on verification. Routes `/forgot-password`,
  `/verify-reset-otp`, `/reset-password`, throttled 8/12/8 per minute.
- `PasswordResetOtp` model, `PasswordResetOtpMail` mailable.
- `frontend/src/admin/pages/ForgotPassword.jsx` — drives the three OTP steps.
- `PasswordResetController` — a separate legacy flow where an admin issues an
  `RST-` code out of band. Also surfaced in `ForgotPassword.jsx` as `mode: 'admin'`.
- `AccountController::updatePassword` — `PUT /me/password`, verifies the current
  password and sets a new one.
- `config/mail.php` and `config/services.php` already declare a `resend` block.
  The `resend/resend-laravel` package is not installed.

## Decisions

The OTP flow is kept. The original request described Laravel's standard
token-link broker flow (emailed link carrying `token` + `email`). Rebuilding
that would discard a hardened, working implementation and require a stable
public SPA URL for the link target. Instead the existing flow is retargeted at a
recovery address.

The legacy `RST-` admin flow is left untouched. It is the offline fallback when
mail delivery fails.

The stack is a Create React App SPA against a Sanctum token API in
`routes/api.php` — not Inertia. There is no server-side redirect to a React
view; the forced-password-change gate is a 403 plus a client route guard.

`App\Models\User` stores credentials in `password_hash`, not `password`.

## Design

### 1. Resend mail driver

- `composer require resend/resend-laravel` (pulls in `resend-php`, registers the
  `resend` transport).
- Verify `config/mail.php` (`resend` block, ~line 64) and `config/services.php`
  (`resend` key, ~line 21) read `env('RESEND_API_KEY')`. Correct them if not.
- `.env.example`:

  ```
  MAIL_MAILER=resend
  RESEND_API_KEY=your_resend_api_key_here
  MAIL_FROM_ADDRESS="noreply@airking.com"
  MAIL_FROM_NAME="Airking Inventory System"
  ```

- Local `.env` stays `MAIL_MAILER=log`. Local development does not consume
  Resend quota or mail real people.
- `DEPLOYMENT.md` gains a note: `airking.com` must be DNS-verified in Resend
  (SPF + DKIM) before sending from `noreply@airking.com`. Until verification
  completes, `onboarding@resend.dev` is the only usable sender.

### 2. Migration

One migration, `add_recovery_email_and_must_change_password_to_users`:

- `recovery_email` — `string(100)`, nullable, positioned after `email`. Not
  unique; two employees may share a household inbox.
- `must_change_password` — `boolean`, default `true`.
- The same migration then runs
  `DB::table('users')->update(['must_change_password' => false])`, so no
  existing user is locked out on deploy. Rows created afterward take `true`
  from the column default.
- `down()` drops both columns.

`recovery_email` is nullable at the database level but required by validation
when creating a user. Existing users therefore keep working, and admins can
backfill the field on edit.

### 3. User model

`app/Models/User.php`:

- Add `recovery_email` and `must_change_password` to `$fillable`.
- Cast `must_change_password` to `boolean`.
- Add notification routing:

  ```php
  public function routeNotificationForMail($notification)
  {
      return $this->recovery_email ?: $this->email;
  }
  ```

- Add the accessor that this feature actually uses:

  ```php
  public function getResetMailAddressAttribute(): string
  {
      return $this->recovery_email ?: $this->email;
  }
  ```

`routeNotificationForMail` is not exercised by this feature. The reset flow
sends a Mailable through `Mail::to(...)->send(...)`, and Laravel consults
notification routing only for Notifications. The method is included because it
makes any future `$user->notify()` correct, but the `reset_mail_address`
accessor is what redirects the reset code.

The `?: $this->email` fallback in both is what preserves reset access for the
existing users who have no `recovery_email`.

### 4. Admin employee creation

- `UserController::store()` validation gains:
  `'recovery_email' => 'required|email|max:100|different:email'`.
  The `different:email` rule prevents an admin pasting the company address into
  both fields, which would silently defeat the mechanism.
- `UserController::update()` gains the same rule as
  `'sometimes|email|max:100|different:email'`, so admins can backfill.
- `frontend/src/admin/components/UserManagementPanel.jsx` gains a "Recovery
  Email" input beside the existing Email field (~line 499). Threaded through
  `formData` initial state, both form-reset helpers (~lines 37, 127, 141),
  `validateForm()` (~line 193), and the create/update payloads (~line 244).
- Labels must distinguish the two: **Email** is the login ID; **Recovery Email**
  is the personal inbox that receives reset codes.

### 5. Forgot-password flow

Three edits to `ForgotPasswordOtpController`. No restructuring.

**`forgot()`** — currently throws `'No account found with that email address.'`
for an unknown email, which lets anyone probe which addresses are real accounts.
Rewrite the lookup:

- If no user matches, return the same 200 success body as the happy path and
  return early. No OTP row, no mail sent.
- If a user matches, mail to `$user->reset_mail_address` instead of
  `$user->email`.
- The success message becomes:
  `"A reset link has been sent to your registered recovery email address."`

The response body carries no hint of which inbox was used and no indication of
whether the account exists. A masked destination was considered and rejected: a
field present only for real accounts leaks exactly what the generic response is
meant to hide.

Accepted cost: a user who mistypes their email receives a success message and no
mail. Step 1 copy must therefore read "if an account exists with that email".

**`reset()`** — inside the existing `DB::transaction`, the `$user->update([...])`
call gains `'must_change_password' => false`.

**Throttling** — unchanged; `/forgot-password` is already `throttle:8,1`.

### 6. Forced password change

`app/Http/Middleware/CheckPasswordChange.php`, aliased `password.changed` in
`bootstrap/app.php` (~line 39, beside the existing `role` and `permission`
aliases).

Behaviour:

- Pass through when there is no authenticated user, or when
  `must_change_password` is false.
- Otherwise return **403** with body
  `{"message": "...", "code": "password_change_required"}`.

Registered by appending `password.changed` to the middleware array on the single
protected group in `routes/api.php` (line 95:
`['auth:sanctum', 'auditor.readonly', 'location.scope', 'extend.sanctum.session']`).

Because it is applied group-wide, the exemptions live inside the middleware as a
route-name or path allowlist checked via `$request->routeIs(...)`, not as
per-route registration:

- `POST /logout` (line 102)
- `GET /user` (line 103) — the identity endpoint the SPA reads
- `PUT /me/password` (line 174)

Without those three exemptions a flagged user cannot load their identity or
submit a new password, and the gate deadlocks.

`AccountController::updatePassword()` gains `'must_change_password' => false` in
its update call. This is what releases the gate.

The flag is cleared by any successful password change — one rule, three call
sites: `ForgotPasswordOtpController::reset()`,
`AccountController::updatePassword()`, and
`UserController::changePassword()`.

### 7. React

**`ChangePassword.jsx`** (new, `src/admin/pages/`) — current password, new
password, confirmation. Posts to `accountAPI.updatePassword`. On success,
refreshes `/me` and navigates to `/admin/dashboard`. Built on the existing
`login.css` shell so it matches `ForgotPassword.jsx`. No sidebar or nav: this is
a terminal screen, not a settings page.

**Route** — `/admin/change-password` in `frontend/src/App.jsx`, rendered inside
the authenticated area but outside `RoleRoute`, so a flagged user can reach it.

**`RoleRoute`** — gains the guard: when the current user has
`must_change_password`, render `<Navigate to="/admin/change-password" replace />`.
`RoleRoute` wraps every admin page in `App.jsx`, so this is a single chokepoint.
This is the convenience half of the gate; the middleware is the enforcing half.

**`api.js`** — `apiRequest` gains a 403 branch beside the existing 401 handling
(~line 270): when the body carries `code === 'password_change_required'`,
redirect to `/admin/change-password`. Covers the case where the flag is set
server-side mid-session.

**`ForgotPassword.jsx`** — copy only. Step 1 becomes "Enter your account email.
If an account exists, we'll send a 6-digit reset code to the recovery email on
file." The three-step OTP machinery is untouched.

### 8. Error handling

`forgot()` already logs and returns a validation error when mail delivery
throws, rather than leaving an orphaned OTP row. That behaviour is preserved.
It must not fire for the unknown-user case, which returns before touching mail.

### 9. Testing

Feature tests:

- Unknown email returns 200 and creates no `PasswordResetOtp` row.
- With `Mail::fake()`, the OTP is addressed to `recovery_email` when set, and
  falls back to `email` when null.
- A successful reset clears `must_change_password`.
- A flagged user receives 403 with `code: password_change_required` on a
  protected route.
- A flagged user can still call `POST /logout`, `GET /user`, and
  `PUT /me/password`.
- `PUT /me/password` clears the flag.
- `UserController::store()` rejects a `recovery_email` equal to `email`.

Manual check with `MAIL_MAILER=log`: confirm `storage/logs/laravel.log` shows
the OTP addressed to the recovery inbox.

## Out of scope

- The legacy `PasswordResetController` `RST-` admin flow.
- The login 2FA OTP in `AuthController` (`users.otp_code`), which is a separate
  mechanism from forgot-password.
- The PWA / Supabase authentication path under `src/pwa`.
