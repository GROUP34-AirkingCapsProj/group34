# Removing the `/admin` URL prefix

**Date:** 2026-10-13
**Status:** Approved for planning
**Scope:** Frontend router paths, the nav-bundle permission map, and backend-generated deep links.

## Motivation

A thesis panelist observed that the application's URLs read `/admin/login` and
`/admin/dashboard`. The prefix carries no routing meaning — there is no
non-admin counterpart to contrast with, and the backend API does not use an
`/admin` namespace at all — so it is noise in every URL the panel sees. The
goal is `/login`, `/dashboard`, `/inventory`, and so on.

## Non-goals

**Opaque record IDs are explicitly out of scope.** The original request also
asked for an unreadable component in the URL, modelled on sites that encode
database IDs (`/ist/42` becoming `/ist/k3Qm7Xp2`). Investigation showed the IST
routes are the only URLs in the application that carry a raw database ID, and
those pages do not currently work — see "Pre-existing defect" below. Hardening
IDs that no functioning page emits would be effort spent on an invisible
surface, so it is deferred until Interbranch Transfers loads. No query string
in the application exposes an ID either, which was verified separately.

Also out of scope: introducing a central route-constants module. Extracting all
398 path literals into a shared `paths.js` is a defensible cleanup, but it turns
a mechanical, greppable rename into a refactor touching every page. It should be
its own change if wanted.

## Pre-existing defect (reported, not fixed here)

`istAPI` in `frontend/src/admin/services/api.js:1214-1239` calls `/ist`,
`/ist/{id}`, `/ist/{id}/receive` and ~13 sibling endpoints. None of them exist:
`php artisan route:list --path=ist` returns no `api/ist/*` route, and
`routes/api.php` defines the live transfer endpoints as `/transfers/{id}/...`
against `TransferController` (lines 939-976). `transferAPI`, in the same file,
already calls those correctly.

Consequently every request from `ISTManagementPage`, `ISTDetailPage`,
`ISTDispatchPage`, `ISTReceivePage`, `ISTReceiptPage` and `ISTCreatePage` 404s.
This is reachable, not dead code: "Interbranch Transfers" is an active sidebar
bundle pointing at `/admin/ist` (`navBundles.js:20`). It arrived with commit
`e95f248 "Transfer feature"`.

This design does not touch it. The rename applies to the IST route paths like
any other, leaving the pages exactly as broken as they are today — no better,
no worse.

## Design

### 1. Router

`frontend/src/App.jsx` holds ~70 route definitions, all prefixed. Each `path`
and each internal `<Navigate to>` target loses the prefix. The root route
changes from `<Navigate to="/admin/login">` to `<Navigate to="/login">`, and the
bare `/admin` route is removed because the catch-all below subsumes it.

No collisions result. The Supabase PWA keeps its own `/pwa` prefix, so `/login`
and `/pwa/login` remain distinct routes.

### 2. Legacy redirect

A single `LegacyAdminRedirect` component mounts at `/admin/*`:

```jsx
<Route path="/admin/*" element={<LegacyAdminRedirect />} />
```

It reads the splat parameter and renders `<Navigate replace>` to `/` + splat,
preserving both `search` and `hash`. `replace` matters — without it the browser
history fills with `/admin` entries and the back button bounces through
redirects.

One component rather than 70 redirect routes, so there is a single place that
defines how a legacy URL is translated.

This is load-bearing beyond stale bookmarks: the backend persists deep links
that already contain the prefix (see §5), and those rows are not rewritten.

### 3. Path literals outside the router

398 route-path occurrences across 59 files — 44 source, 15 test.

A naive search-and-replace over `/admin` is wrong. Some occurrences are module
and asset import paths that must not change:

- `../../admin/styles/dashboard_air.css` and four sibling stylesheet imports
- `../../admin/components/ThemeToggle`
- `./admin/pages/*` lazy imports throughout `App.jsx`
- file-path comments naming `frontend/src/admin/utils/*`

The discriminator: a route path is `/admin/...` **not** preceded by `.` or a
word character, and whose next segment is not one of `styles`, `components`,
`pages`, `utils`, `hooks`, `services`, or `context`. Verified: zero occurrences
are API paths — no `apiRequest`/`axios`/`fetch` call targets `/admin`, and
`routes/api.php` defines no `/admin` endpoint — so nothing server-facing is at
risk from this change.

Four files deserve individual attention, because a miss there fails in a way
that is easy to mistake for an unrelated bug:

- **`admin/services/api.js:282,334`** — the 401/419 handler's
  `window.location.href` hard redirect. Missed, an expired session lands on a
  blank page instead of the login screen.
- **`admin/components/RoleRoute.jsx`** — seven occurrences, including the
  `location.pathname === '/admin/dashboard'` comparison that lets the dashboard
  render while permissions are still hydrating. Missed, the dashboard hangs on
  "Checking access…".
- **`admin/components/SessionIdleGuard.jsx:16`** — the
  `pathname === '/admin/login'` check exempting the login screen from the idle
  timer. Missed, the login page begins logging the user out.
- **`admin/utils/navBundles.js` and `admin/utils/roles.js`** — the
  route-to-permission maps, matched against `location.pathname`. A stale entry
  does not error; the page silently redirects to the dashboard.

### 4. Database: `nav_permission_bundles.route_path`

The prefix is also persisted. `NavBundleController` serves `route_path` to the
frontend, and `RoleRoute` matches those values against the live pathname, so the
column must move in step with the router. The live table holds 31 bundles, all
31 prefixed.

A new migration rewrites every `route_path` from `/admin/%` to `/%`, with a
`down()` restoring the prefix. The rewrite is prefix-guarded so re-running is
safe.

`database/seeders/NavBundleSeeder.php` is updated to the unprefixed paths **in
the same change**. This simultaneity is a correctness requirement, not tidiness:
the seeder calls `updateOrCreate(['route_path' => $routePath])`, so an updated
seeder run against an un-migrated database matches nothing and *duplicates* all
31 bundles rather than updating them.

Two historical migrations (`2026_10_07_000001_add_audit_evidence_permission`,
`2026_10_12_000001_drop_budget_management`) contain prefixed literals. They are
left untouched — rewriting applied migrations is worse than letting the new
migration normalise whatever they produced.

Per the project's local-database notes, the migration must be applied to the
test database as well, not only the development one.

### 5. Backend-generated deep links

Nine hardcoded links lose the prefix:

- `app/Http/Controllers/Api/AlertSettingsController.php:117,124,131,138,145,152,159`
- `app/Services/WarehouseNotificationService.php:37,61`

Links already written to persisted notification rows keep the old prefix. They
are deliberately not back-filled; the §2 redirect resolves them, which is part
of why that redirect is in the design rather than optional.

## Testing

- The 15 affected frontend test files are updated to the new paths. They are
  genuine assertions about redirect targets and permission gating, so they
  should fail before the source change and pass after — not be edited to match.
- A new router test asserts `/admin/dashboard` redirects to `/dashboard`, and
  that search and hash survive the redirect.
- A new backend test asserts no active `nav_permission_bundles` row retains an
  `/admin/` prefix. `tests/Feature/Permissions/NavBundleTest.php` already
  inspects `route_path`, so it is the natural home.
- The existing nav-bundle test asserting one required permission per bundle
  guards against the seeder-duplication failure mode in §4, and must pass.
- Manual check of the four §3 landmines: expired-session redirect, dashboard
  hydration, idle timer on the login page, and sidebar navigation to a
  permission-gated page.

## Risks

The seeder/migration ordering in §4 is the one step that can corrupt data rather
than merely break a page. Everything else fails visibly and is recoverable by
fixing a string.

The breadth — 398 occurrences, 59 files — makes an incomplete rename the likeliest
outcome. The mitigation is the §3 discriminator plus a final search proving no
route-form `/admin` literal survives outside the legacy-redirect component.
