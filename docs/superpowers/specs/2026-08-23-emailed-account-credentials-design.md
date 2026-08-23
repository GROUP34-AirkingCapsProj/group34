# Emailed Account Credentials

**Date:** 2026-08-23
**Status:** Approved

## Problem

An admin creates an account by typing a password into the user form. Nothing tells
the new user that the account exists. The admin has to relay the login email and
password out-of-band — over chat, on paper, in person — and often picks a weak,
reused default while doing it.

The enforcement half of this already works. `must_change_password` defaults to
`true` on new rows, `CheckPasswordChange` blocks the API until the user replaces
the password, and the frontend has a forced change-password screen. What is
missing is delivery: the user is never told what their credentials are.

## Solution

On creation the system generates a strong temporary password, emails it to the
user's recovery address along with their login email, and keeps the existing
first-login gate. The admin never sees or chooses the password.

## Decisions

| Decision | Choice | Reasoning |
|---|---|---|
| Password source | System-generated | The admin never handles the secret, so there is nothing to leak over chat and no weak reused default. |
| Delivery target | `recovery_email` only | Already required, already validated as different from the login email, and already the channel for forgot-password OTPs. The login email may be a company address with no live mailbox. |
| Send failure | Keep the account, warn the admin, offer resend | A flaky mail server must not block onboarding entirely. |
| Temp password expiry | None | The `must_change_password` gate makes the credential single-use in practice. An expiry window adds a column, a login-path check, and a new way to lock out a hire who starts next week. |
| Resend location | Third button in the user-list row | Where the admin already is when someone reports a missing login. |
| Resend eligibility | Only while `must_change_password` is `true` | Prevents an admin silently resetting the password of a user who already completed first login. |

## Architecture

One new service owns the whole operation:

```php
App\Services\TemporaryCredentialIssuer::issue(User $user, User $actor): bool
```

`$actor` is required, not nullable. `PasswordAuditLogger` supports a
subject-as-actor fallback, but that exists for the *unauthenticated*
forgot-password routes, where there genuinely is no session. Both callers here
sit behind `permission:user_account_management` and always have
`$request->user()`. Allowing the fallback would let a caller write an audit row
saying user X reset user X's own password — indistinguishable, to an operator
reading the trail, from the employee having done it themselves. A confidently
wrong audit record is worse than a missing one.

It generates the password, saves the hash with `must_change_password = true`,
mails the plaintext to `recovery_email`, writes an audit entry, and returns
whether delivery succeeded. The plaintext lives only as a local variable and in
the outgoing message — never returned, never stored, never logged.

Two callers:

- `UserController::store()` — after the user row and location pivot commit
- `UserController::resendCredentials()` — `POST /users/{user}/resend-credentials`,
  behind `permission:user_account_management,update`

Factoring it into a service rather than inlining it means password strength, the
delivery target, and the audit entry are each defined in exactly one place, and
the resend path cannot drift from the create path. This mirrors how
`PasswordAuditLogger` is already factored out.

Sending is synchronous via `Mail::to()->send()`, matching
`ForgotPasswordOtpController`. `QUEUE_CONNECTION=sync`, so queueing would run
inline anyway while making the failure result harder to report back to the admin.

## Components

### `App\Services\TemporaryCredentialIssuer` (new)

- `issue(User $user, ?User $actor): bool`
- Private `generate()` builds a 16-character password with `random_int()` over
  explicit character classes: one guaranteed character from each of lowercase,
  uppercase, digits, and symbols, the remainder drawn at random, then shuffled.
  It satisfies `Password::min(8)->mixedCase()->numbers()->symbols()` by
  construction rather than by luck.
- Wraps the send in `try/catch`; returns `false` and logs
  `Log::warning('credential_mail_failed', …)` on failure.
- Audits via `PasswordAuditLogger::ADMIN_CHANGE` with
  `['method' => 'credentials_emailed']`.

### `App\Mail\AccountCredentialsMail` (new)

Constructor takes `User $user` and `string $plainPassword`. Subject: "Your
Airking Inventory account". Same shape as `PasswordResetOtpMail`.

### `resources/views/emails/account_credentials.blade.php` (new)

States the login email (`$user->email`), the username, and the temporary
password. Says plainly that the password must be changed at first login and that
the account is blocked until then. Follows the styling of
`password_reset_otp.blade.php`.

### `UserController` (modified)

- `store()`: drop `password` and `password_confirmation` from validation and drop
  the hashing step; call the issuer instead. Add a boolean `credentials_emailed`
  key to the `data` object the `ApiResponse` trait returns, alongside the
  serialised user — not at the envelope's top level.
- `resendCredentials()`: new method calling the same issuer, guarded on
  `must_change_password` and on the user not being archived.

### `UserManagementPanel.jsx` (modified)

- Create mode: remove both password inputs and their validation branches; add a
  note that credentials will be emailed to the recovery address.
- Edit mode: password fields stay unchanged. An admin setting an existing user's
  password is a separate, already-working path.
- Add a "Resend" button beside Edit and Deactivate.
- Show an amber warning when `credentials_emailed` is `false`.

### `routes/api.php` (modified)

One line for the new POST route.

## Data flow

**Create**

1. Admin submits the form — no password field.
2. `store()` validates, creates the user row, syncs locations.
3. `issue()` generates the password, saves the hash with
   `must_change_password = true`, mails it to `recovery_email`.
4. Response `201` with `credentials_emailed: true|false`. On `false` the panel
   shows an amber warning naming the recovery address and pointing at Resend.
5. User logs in; `CheckPasswordChange` blocks every API call; the forced
   change-password screen takes over; `AccountController` clears the flag.

**Resend**

Steps 3–4 on an existing user, returning `200`.

Resend issues a brand-new password and invalidates the old one. That is correct
for someone who never received the first mail, which is why it is restricted to
accounts still carrying `must_change_password = true`. Anyone past first login
uses the forgot-password OTP flow.

The rotation necessarily happens before the send: the hash must be saved for the
emailed password to work. A failed send therefore leaves a valid-but-undelivered
password, which is the exact state Resend clears.

## Error handling

| Failure | Behaviour |
|---|---|
| Mail throws during `store()` | User kept. `issue()` returns `false`, logs a warning. Response `201` with `credentials_emailed: false`. Panel warns the admin. |
| Mail throws during resend | `200` with `credentials_emailed: false`. The password was rotated; only the mail failed. Admin retries. |
| Resend on `must_change_password = false` | `422` directing the admin to the forgot-password flow. Password untouched. |
| Resend on an archived/soft-deleted user | `422`. No credential issued for an account nobody should be logging into. |
| Audit write fails | Already swallowed by `PasswordAuditLogger`; the credential operation completes. |

## Testing

New `back-end/tests/Feature/Users/CredentialIssuanceTest.php`, using
`Mail::fake()`:

- Creating a user sends `AccountCredentialsMail` to `recovery_email`, not `email`
- The generated password satisfies the app's own `Password` rule — asserted by
  running the validator against the captured plaintext, so the generator and the
  policy cannot drift apart
- The new user has `must_change_password = true` and the emailed password
  authenticates at `/api/login`
- `store()` no longer accepts a `password` field; sending one does not set the hash
- Mail failure keeps the user row and returns `credentials_emailed: false`
- Resend rotates the password: the old one stops working, the new one works
- Resend returns `422` on a user with `must_change_password = false` and leaves
  the hash unchanged
- An audit row is written with `password_changed_by_admin` /
  `credentials_emailed` and contains no plaintext

Existing tests to update: anything in `RbacRouteMatrixTest` covering
`POST /users`, plus any user-creation helper passing a `password`. These will be
located and fixed during implementation.

Frontend: extend existing `UserManagementPanel` tests if present. No new JS test
infrastructure — the panel change is field removal plus one button.

## Out of scope

- Changing how an admin sets an existing user's password (`changePassword` stays as-is)
- Temp password expiry
- Verifying that the recovery address is reachable before account creation
- Any change to the forgot-password OTP flow
