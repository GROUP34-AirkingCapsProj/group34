# Access Control Merge (Role Permissions + User Overrides Tabs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge the Role Permissions and User Permission Overrides pages into a single page with two URL-driven top-level tabs, and remove the standalone "User Overrides" sidebar item.

**Architecture:** A new thin parent page `AccessControlPage` owns one `AdminLayout` and a top-level tab bar; the active tab is derived from the URL (`/admin/roles` = Role Permissions, `/admin/user-permissions` = User Overrides) and tab clicks `navigate()` between those paths. The two existing pages are stripped of their own `AdminLayout` wrapper and rendered as panels inside the parent, keeping all their internals (including their existing inner sub-tabs).

**Tech Stack:** React (CRA, `frontend/`), react-router-dom, Jest + React Testing Library. No backend changes.

**Spec:** `docs/superpowers/specs/2026-06-30-access-control-merge-tabs-design.md`

---

## File Structure

- `frontend/src/admin/pages/AccessControlPage.jsx` — **new** parent: `AdminLayout` + top tab bar + active panel; URL-driven.
- `frontend/src/admin/pages/AccessControlPage.test.jsx` — **new** unit test for tab/route wiring (panels mocked).
- `frontend/src/admin/pages/RolePermissions.jsx` — **modify**: drop its `AdminLayout` wrapper + import; return the `rbac-console` root directly.
- `frontend/src/admin/pages/UserPermissionOverrides.jsx` — **modify**: same `AdminLayout` strip.
- `frontend/src/App.jsx` — **modify**: lazy-import `AccessControlPage`; both `/admin/roles` and `/admin/user-permissions` render it; remove the now-unused `RolePermissions`/`UserPermissionOverrides` lazy imports.
- `frontend/src/admin/components/Sidebar.jsx` — **modify**: remove the "User Overrides" nav item; repoint the `/admin/roles` prefetch to `AccessControlPage`.
- `frontend/src/admin/styles/rbac_console.css` — **modify**: add `.rcx-toptabs` / `.rcx-toptab` page-level tab styles.

Untouched: `roles.js` (both paths already admin-only), all backend/permission code, `PermissionAuditLog`.

**Note on TDD:** The new parent's wiring is unit-tested (Task 1, test-first). The two panel edits are pure wrapper removals with no isolated test harness (the panels make many API calls), so they are verified by ESLint + the full test run + manual checks in the final task.

---

## Task 1: Create `AccessControlPage` with passing tests

**Files:**
- Create: `frontend/src/admin/pages/AccessControlPage.jsx`
- Create: `frontend/src/admin/pages/AccessControlPage.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/pages/AccessControlPage.test.jsx`:

```jsx
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AccessControlPage from './AccessControlPage';

const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({
  ...jest.requireActual('react-router-dom'),
  useNavigate: () => mockNavigate,
}));

// Keep the parent isolated from the panels' API calls / layout chrome.
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('./RolePermissions', () => () => <div>ROLE PANEL</div>);
jest.mock('./UserPermissionOverrides', () => () => <div>OVERRIDES PANEL</div>);

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <AccessControlPage />
    </MemoryRouter>
  );

beforeEach(() => mockNavigate.mockClear());

test('renders Role Permissions panel and active tab at /admin/roles', () => {
  renderAt('/admin/roles');
  expect(screen.getByText('ROLE PANEL')).toBeInTheDocument();
  expect(screen.queryByText('OVERRIDES PANEL')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Role Permissions' }).className).toMatch(/is-active/);
});

test('renders User Overrides panel and active tab at /admin/user-permissions', () => {
  renderAt('/admin/user-permissions');
  expect(screen.getByText('OVERRIDES PANEL')).toBeInTheDocument();
  expect(screen.queryByText('ROLE PANEL')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'User Overrides' }).className).toMatch(/is-active/);
});

test('clicking the inactive tab navigates to its path', () => {
  renderAt('/admin/roles');
  fireEvent.click(screen.getByRole('button', { name: 'User Overrides' }));
  expect(mockNavigate).toHaveBeenCalledWith('/admin/user-permissions');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && set CI=true && npx react-scripts test --watchAll=false --testPathPattern="AccessControlPage"`
Expected: FAIL — cannot find module `./AccessControlPage` (not created yet).

- [ ] **Step 3: Create the component**

Create `frontend/src/admin/pages/AccessControlPage.jsx`:

```jsx
import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import RolePermissions from './RolePermissions';
import UserPermissionOverrides from './UserPermissionOverrides';
import '../styles/rbac_console.css';

const TABS = [
  { key: 'roles',     label: 'Role Permissions', path: '/admin/roles' },
  { key: 'overrides', label: 'User Overrides',   path: '/admin/user-permissions' },
];

const AccessControlPage = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const activeKey = location.pathname === '/admin/user-permissions' ? 'overrides' : 'roles';

  return (
    <AdminLayout>
      <div className="rcx-toptabs">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`rcx-toptab${activeKey === t.key ? ' is-active' : ''}`}
            onClick={() => { if (activeKey !== t.key) navigate(t.path); }}
          >
            {t.label}
          </button>
        ))}
      </div>
      {activeKey === 'roles' ? <RolePermissions /> : <UserPermissionOverrides />}
    </AdminLayout>
  );
};

export default AccessControlPage;
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && set CI=true && npx react-scripts test --watchAll=false --testPathPattern="AccessControlPage"`
Expected: PASS — 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/AccessControlPage.jsx frontend/src/admin/pages/AccessControlPage.test.jsx
git commit -m "feat(rbac): add AccessControlPage tabbed parent for role/user permissions"
```

---

## Task 2: Strip `AdminLayout` from `RolePermissions`

**Files:**
- Modify: `frontend/src/admin/pages/RolePermissions.jsx`

The panel must no longer render its own `AdminLayout` (the parent provides it). It returns its
`rbac-console` root directly.

- [ ] **Step 1: Remove the AdminLayout import**

In `frontend/src/admin/pages/RolePermissions.jsx`, delete this line (line 2):

```jsx
import AdminLayout from '../components/AdminLayout';
```

- [ ] **Step 2: Remove the opening `<AdminLayout>` wrapper**

Find the return opening:

```jsx
  return (
    <AdminLayout>
      <div className="rbac-console">
```

Replace with:

```jsx
  return (
      <div className="rbac-console">
```

- [ ] **Step 3: Remove the closing `</AdminLayout>` wrapper**

Find the end of the component's JSX:

```jsx
      </div>
    </AdminLayout>
  );
};
```

Replace with:

```jsx
      </div>
  );
};
```

- [ ] **Step 4: Verify no AdminLayout references remain**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "AdminLayout" -- frontend/src/admin/pages/RolePermissions.jsx`
Expected: no output.

- [ ] **Step 5: Lint the file**

Run: `cd frontend && npx eslint src/admin/pages/RolePermissions.jsx`
Expected: exit 0, no errors (no unused-import warning for AdminLayout).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/RolePermissions.jsx
git commit -m "refactor(rbac): RolePermissions renders as a panel (drop AdminLayout wrapper)"
```

---

## Task 3: Strip `AdminLayout` from `UserPermissionOverrides`

**Files:**
- Modify: `frontend/src/admin/pages/UserPermissionOverrides.jsx`

- [ ] **Step 1: Remove the AdminLayout import**

In `frontend/src/admin/pages/UserPermissionOverrides.jsx`, delete this line (line 2):

```jsx
import AdminLayout from '../components/AdminLayout';
```

- [ ] **Step 2: Remove the opening `<AdminLayout>` wrapper**

Find the return opening:

```jsx
  return (
    <AdminLayout>
      <div className="rbac-console">
```

Replace with:

```jsx
  return (
      <div className="rbac-console">
```

- [ ] **Step 3: Remove the closing `</AdminLayout>` wrapper**

Find the end of the component's JSX:

```jsx
      </div>
    </AdminLayout>
  );
};
```

Replace with:

```jsx
      </div>
  );
};
```

- [ ] **Step 4: Verify no AdminLayout references remain**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "AdminLayout" -- frontend/src/admin/pages/UserPermissionOverrides.jsx`
Expected: no output.

- [ ] **Step 5: Lint the file**

Run: `cd frontend && npx eslint src/admin/pages/UserPermissionOverrides.jsx`
Expected: exit 0, no errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/UserPermissionOverrides.jsx
git commit -m "refactor(rbac): UserPermissionOverrides renders as a panel (drop AdminLayout wrapper)"
```

---

## Task 4: Wire routes and remove the sidebar item

**Files:**
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/admin/components/Sidebar.jsx`

- [ ] **Step 1: Swap the lazy imports in App.jsx**

In `frontend/src/App.jsx`, find the "SA Permission Editor" import block:

```jsx
// SA Permission Editor
const RolePermissions       = lazy(() => import('./admin/pages/RolePermissions'));
const UserPermissionOverrides = lazy(() => import('./admin/pages/UserPermissionOverrides'));
const PermissionAuditLog    = lazy(() => import('./admin/pages/PermissionAuditLog'));
```

Replace with (the two panels are now imported by `AccessControlPage`, not the router):

```jsx
// SA Permission Editor
const AccessControlPage     = lazy(() => import('./admin/pages/AccessControlPage'));
const PermissionAuditLog    = lazy(() => import('./admin/pages/PermissionAuditLog'));
```

- [ ] **Step 2: Point both routes at AccessControlPage**

In `frontend/src/App.jsx`, find:

```jsx
          <Route path="/admin/roles" element={<RoleRoute><RolePermissions /></RoleRoute>} />
          <Route path="/admin/user-permissions" element={<RoleRoute><UserPermissionOverrides /></RoleRoute>} />
```

Replace with:

```jsx
          <Route path="/admin/roles" element={<RoleRoute><AccessControlPage /></RoleRoute>} />
          <Route path="/admin/user-permissions" element={<RoleRoute><AccessControlPage /></RoleRoute>} />
```

- [ ] **Step 3: Remove the "User Overrides" sidebar item**

In `frontend/src/admin/components/Sidebar.jsx`, in `adminMenuSections` (ADMINISTRATION
section), delete this line:

```jsx
      { name: 'User Overrides', path: '/admin/user-permissions', icon: 'roles' },
```

Leave the `Role & Permissions` item (`/admin/roles`) in place.

- [ ] **Step 4: Repoint the prefetch entry**

In `frontend/src/admin/components/Sidebar.jsx`, in `routePrefetchMap`, find:

```jsx
  '/admin/roles': () => import('../pages/RolePermissions'),
```

Replace with:

```jsx
  '/admin/roles': () => import('../pages/AccessControlPage'),
```

- [ ] **Step 5: Verify the old references are gone and lint**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "User Overrides\|RolePermissions\|UserPermissionOverrides" -- frontend/src/App.jsx frontend/src/admin/components/Sidebar.jsx`
Expected: no output.

Run: `cd frontend && npx eslint src/App.jsx src/admin/components/Sidebar.jsx`
Expected: exit 0, no new errors (Sidebar.jsx may still show its pre-existing unused-import warnings; no NEW errors from this change).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/App.jsx frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(rbac): route /admin/roles and /admin/user-permissions to tabbed AccessControlPage; drop User Overrides nav item"
```

---

## Task 5: Add top-level tab styles

**Files:**
- Modify: `frontend/src/admin/styles/rbac_console.css`

- [ ] **Step 1: Add the `.rcx-toptabs` styles after the existing `.rcx-tab` rules**

In `frontend/src/admin/styles/rbac_console.css`, find the existing inner-tab block ending with:

```css
.rcx-tab.is-active { color: var(--rc-crimson); border-bottom-color: var(--rc-crimson); }
```

Immediately after that line, add:

```css

/* ===================== TOP-LEVEL PAGE TABS ===================== */
.rcx-toptabs { display: flex; gap: 0.4rem; margin-bottom: 1.25rem; }
.rcx-toptab {
  font-family: var(--rc-display); font-weight: 700; font-size: 0.95rem;
  padding: 0.7rem 1.4rem; border: 1px solid var(--rc-line); border-radius: 10px;
  background: var(--rc-card); cursor: pointer; color: var(--rc-ink-soft);
  transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}
.rcx-toptab:hover { color: var(--rc-ink); }
.rcx-toptab.is-active { color: #fff; background: var(--rc-crimson); border-color: var(--rc-crimson); }
```

(These reuse CSS variables already defined and used elsewhere in this file: `--rc-display`,
`--rc-line`, `--rc-card`, `--rc-ink-soft`, `--rc-ink`, `--rc-crimson`.)

- [ ] **Step 2: Commit**

```bash
git add frontend/src/admin/styles/rbac_console.css
git commit -m "style(rbac): add page-level tab styling for AccessControlPage"
```

---

## Task 6: Full verification

**Files:**
- None (verification only).

- [ ] **Step 1: Run the RBAC-related test suites**

Run: `cd frontend && set CI=true && npx react-scripts test --watchAll=false --testPathPattern="AccessControlPage|RoleRoute"`
Expected: PASS — all AccessControlPage (3) and RoleRoute (4) tests pass.

- [ ] **Step 2: Lint all touched frontend files**

Run: `cd frontend && npx eslint src/admin/pages/AccessControlPage.jsx src/admin/pages/RolePermissions.jsx src/admin/pages/UserPermissionOverrides.jsx src/App.jsx src/admin/components/Sidebar.jsx`
Expected: exit 0, no errors (Sidebar.jsx pre-existing unused-import warnings are acceptable; no errors).

- [ ] **Step 3: Manual verification (dev server)**

Run: `cd frontend && npm start`
Then, logged in as admin:
1. Sidebar → ADMINISTRATION shows "Role & Permissions" but no "User Overrides".
2. Navigate to `/admin/roles` → page loads with the **Role Permissions** top tab active; its inner "Navigation Access / Advanced · Full Matrix" sub-tabs, Save button, legend, and Configure modal all work.
3. Click the **User Overrides** top tab → URL changes to `/admin/user-permissions`; the user picker, tri-state overrides, reason field, and modal all work.
4. Click **Role Permissions** top tab → URL returns to `/admin/roles`.
5. Load `/admin/user-permissions` directly → opens with the User Overrides tab active.
6. Log in as a non-admin and hit `/admin/roles` → redirected to the dashboard.

- [ ] **Step 4: Done**

All checks pass → feature complete. Consider invoking superpowers:finishing-a-development-branch.
