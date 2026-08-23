# Mandatory Two-Factor Authentication for Admins

**Date:** 2026-08-23
**Status:** Approved

## Problem

The admin role can reach every feature in the system. It is the account worth
stealing, and it is the one currently least protected.

Two-factor authentication already exists: `AuthController::login()` mails a
six-digit code on a correct password and `verifyOtp()` exchanges it for a token.
Three things undermine it.

1. **It is switched off.** `two_factor_auth_enabled` is `'0'` in the database.
   The setting defaults to *on* when absent, so this was a deliberate change.
2. **It is all-or-nothing.** One global `SystemSetting` governs every account.
   There is no way to require it for admins while sparing warehouse staff.
3. **An admin can switch it off from the admin UI.** The account with the most
   to lose can disable its own protection.

Two smaller defects sit alongside it: the login OTP is stored in **plaintext**
in `users.otp_code` — while the password-reset OTP beside it stores a hash — and
it is mailed to the *login* address rather than the personal recovery address.

## Solution

Two-factor authentication becomes unconditional for admins and independent of
the global toggle. The toggle continues to govern every other role. Every code
that is issued goes to the user's `recovery_email`.

| | Toggle ON | Toggle OFF |
|---|---|---|
| **Admin** | OTP → recovery email | OTP → recovery email (toggle ignored) |
| **Every other role** | OTP → recovery email | No OTP; token issued directly |

## Decisions

| Decision | Choice | Reasoning |
|---|---|---|
| Mechanism | Keep email OTP | The flow already works end to end, frontend included. TOTP would be a stronger factor but is a separate feature: enrolment, secret storage, recovery codes, and a lost-phone lockout story. |
| Delivery address | `recovery_email`, for everyone | Separates the factor from the login address an attacker already knows. |
| Missing recovery address | Capture it mid-login | Requiring the address without a way to supply it would lock out the seeded admin, who has none. |
| Toggle scope | Non-admins only | Smallest blast radius that closes the hole. Admins are always covered; a separate "admin 2FA" setting would itself be switchable from the admin UI, reopening it. |
| OTP storage | Hash it | `verifyOtp()` is being rewritten anyway. Removes a case where database read access alone completes a stolen-password login. |
| Mail failure | Refuse the login | An auth control that can be bypassed by breaking mail delivery is not a control. Fails closed; see Risks. |

## Architecture

`App\Services\TwoFactorPolicy` owns the rule:

```php
requiresOtp(User $user): bool           // admin => true; otherwise the global setting
requiresRecoveryEmail(User $user): bool // requiresOtp() && recovery_email is null
```

`AuthController::login()` consults it after the password and status checks,
replacing the direct `SystemSetting::twoFactorAuthEnabled()` call. Login now has
three outcomes rather than two:

| Condition | Response |
|---|---|
| No OTP required | Token, as today |
| OTP required, recovery address on file | `requires_otp: true`, code mailed |
| OTP required, no recovery address | `requires_recovery_email: true` |

Putting the rule in a service rather than inlining it keeps `login()` — already
about 110 lines of rate limiting, CAPTCHA, status checks and audit — from
growing, and makes the rule testable without going through HTTP. It matches how
`LoginCaptchaService` and `TemporaryCredentialIssuer` are already factored out.

## Components

### `App\Services\TwoFactorPolicy` (new)

The two predicates above. The only place that knows admins are exempt from the
toggle.

### `App\Services\LoginOtpIssuer` (new)

Generates the six-digit code, stores `Hash::make($code)` in `users.otp_code`
with a ten-minute expiry, and mails it to `$user->recovery_email`. Returns
whether the mail was delivered.

Both `login()` and the bootstrap endpoint call it, so the two paths cannot drift
apart on code length, expiry, or delivery target.

### `App\Mail\LoginOtpMail` (new)

The existing `SendOTPMail` says only "your OTP". A login code now arrives at a
*personal* inbox, so it must name the system and the account it belongs to,
otherwise the recipient cannot tell what they are approving. Follows
`PasswordResetOtpMail`.

### `AuthController` (modified)

- `login()`: three-way branch via `TwoFactorPolicy`.
- `submitRecoveryEmail()`: new; the bootstrap path.
- `verifyOtp()`: look the user up by email, then `Hash::check` the code against
  a live expiry, instead of matching `otp_code` in the query.

### `User` (modified)

Add `isAdmin(): bool`. `$user->role?->role_name === 'admin'` is currently
duplicated across at least five files; this change would add a sixth. Extract
the one being introduced rather than copying the string again. Existing call
sites are left alone — that is unrelated refactoring.

### `SystemConfiguration.jsx` (modified)

Relabel the toggle and state that admins are always covered, so switching it off
does not read as "MFA is now off system-wide".

### `Login.jsx` (modified)

Handle `requires_recovery_email` with a one-field screen, alongside the existing
`requires_otp` handling.

### Routes (modified)

`POST /api/login/recovery-email`, throttled to match `/api/login`.

## Data flow

**Login for a user who needs a code**

1. Password, CAPTCHA, status, rate limiters — unchanged.
2. `TwoFactorPolicy::requiresOtp()` returns true.
3. No recovery address → return `requires_recovery_email: true`. No token, no
   code, nothing written.
4. Otherwise `LoginOtpIssuer` stores the hash and mails the code to
   `recovery_email`.
5. `verifyOtp` resolves the user by email, `Hash::check`s against a live expiry,
   clears the code, issues the token.

**Bootstrap**

`POST /api/login/recovery-email` takes `email`, `password`, `recovery_email`. It
**re-verifies the password**: the pending login state is not a credential, so
knowing an email address must never be enough to write to an account. The
address is validated with the same rules `UserController::store()` applies
(`different:email`, unique across both `recovery_email` and `email`), saved, and
step 4 runs immediately.

**Toggle off, non-admin**

Policy returns false. Token issued directly. Unchanged from today.

## Error handling

| Failure | Behaviour |
|---|---|
| OTP mail throws | Login refused, `503`, message naming the recovery address. No token. |
| No recovery address on file | `requires_recovery_email`; bootstrap screen. Not an error. |
| Bootstrap password wrong | `422`. Address not saved. |
| Bootstrap address already in use | `422`, same message `store()` gives. |
| OTP wrong or expired | `422`, unchanged. Route stays throttled at 12/min. |

## Risks

**Mail delivery becomes a hard dependency for administrative access.** Failing
closed is the correct choice for an auth control, but the consequence must be
stated rather than discovered: if SMTP breaks, every admin is locked out until
it is fixed — including the admin who would fix it. Recovery requires database
or console access to the server.

`DEPLOYMENT.md` must record this beside the existing mail note. Operators need
to know that `MAIL_*` configuration now gates administrative login, not only
onboarding and password resets.

## Testing

New `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`:

- An admin with the toggle **off** still receives an OTP and gets **no** token —
  the central claim of this change
- A non-admin with the toggle off receives a token and no mail
- A non-admin with the toggle on receives an OTP
- The code is mailed to `recovery_email` and never to `email`
- `users.otp_code` never equals the mailed code, and the mailed code still
  verifies — proves hashing without asserting on the algorithm
- A user needing an OTP with no recovery address gets `requires_recovery_email`
  and no token
- The bootstrap endpoint rejects a wrong password, rejects a duplicate address,
  and on success mails the code
- Mail failure refuses the login and issues no token
- An expired code fails

Existing suites to check: anything asserting that login returns a token
directly, and `MustChangePasswordTest`.

**Ordering of the two gates.** A user can now face both an OTP challenge and a
forced password change. OTP comes first, and this needs no new code: `login()`
returns `requires_otp` *instead of* a token, while `must_change_password` is
enforced by `CheckPasswordChange` on already-authenticated routes. No token
means the middleware is never reached. Add one test pinning this, so a future
refactor that issues a token earlier cannot silently invert the order.

Frontend: add `frontend/src/admin/pages/Login.test.jsx`, following the existing
page-test pattern (`AccessControlPage.test.jsx`, `SystemReports.test.jsx`) with
`../services/api` mocked. Two cases: a `requires_otp` response shows the code
entry screen, and a `requires_recovery_email` response shows the address screen.

## Out of scope

- TOTP / authenticator apps
- SMS as a factor (`SmsService` exists but is unused for auth)
- Remembering a device to skip the second factor
- Refactoring the five existing `role_name === 'admin'` call sites
- Changing the password-reset OTP flow
