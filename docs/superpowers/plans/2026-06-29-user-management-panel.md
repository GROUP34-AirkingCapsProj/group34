# User Management Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken "User Accounts" table card in the System Configuration → User Management tab with the real, full-featured User Management UI, extracted into one shared component reused by the sidebar page.

**Architecture:** Move the entire body of `UserManagement.jsx` into a new presentational-but-self-contained component `UserManagementPanel.jsx` (everything except `AdminLayout` and the `ak-header` page title). The sidebar page becomes a thin wrapper around the panel; the System Config tab embeds the same panel in place of its dead-button table card.

**Tech Stack:** React (CRA / react-scripts), Jest + React Testing Library, existing `usersAPI` / `batchAPI` / `authAPI` services.

Spec: `docs/superpowers/specs/2026-06-29-user-management-panel-design.md`

---

## File Structure

- **Create** `frontend/src/admin/components/UserManagementPanel.jsx` — all User Management state, fetching, handlers, toolbar (Add button), stats, filters, table, pagination, modal. No `AdminLayout`, no `ak-header`.
- **Create** `frontend/src/admin/components/UserManagementPanel.test.jsx` — smoke test with mocked API.
- **Modify** `frontend/src/admin/pages/UserManagement.jsx` — shrink to `AdminLayout` + `ak-header` + `<UserManagementPanel />`.
- **Modify** `frontend/src/admin/pages/SystemConfiguration.jsx` — swap the "User Accounts" card for `<UserManagementPanel />`, remove the old table's state/derived values, hide the footer on the `users` tab.

---

## Task 1: Extract `UserManagementPanel` from `UserManagement.jsx`

**Files:**
- Create: `frontend/src/admin/components/UserManagementPanel.jsx`
- Modify: `frontend/src/admin/pages/UserManagement.jsx`

This is a mechanical move. Start the new component as a copy of the current page, then strip the page chrome and relocate the Add button.

- [ ] **Step 1: Copy the page file to the new component path**

```bash
cd frontend
cp src/admin/pages/UserManagement.jsx src/admin/components/UserManagementPanel.jsx
```

- [ ] **Step 2: Fix relative import paths in the new component**

The file moved from `pages/` to `components/`, so sibling-dir imports that were `../components/...` are now `./...`, and `../services` / `../utils` / `../styles` stay the same depth (still one level up). Edit the import block at the top of `src/admin/components/UserManagementPanel.jsx`:

Old:
```jsx
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import PasswordFieldWithToggle from '../components/PasswordFieldWithToggle';
import ArchiveButton from '../components/ArchiveButton';
import { usersAPI, authAPI, batchAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/user_management.css';
import '../styles/archive_management.css';
```

New (drop `AdminLayout`, change component imports to `./`):
```jsx
import Loader from './Loader';
import Modal from './Modal';
import PasswordFieldWithToggle from './PasswordFieldWithToggle';
import ArchiveButton from './ArchiveButton';
import { usersAPI, authAPI, batchAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/user_management.css';
import '../styles/archive_management.css';
```

- [ ] **Step 3: Rename the component and drop the page chrome from its `return`**

In `src/admin/components/UserManagementPanel.jsx`:

Rename the declaration:
```jsx
const UserManagementPanel = () => {
```
and the export at the bottom:
```jsx
export default UserManagementPanel;
```

Replace the opening of the `return (` block. Old:
```jsx
  return (
    <AdminLayout>
     <div className="ak-page">
      <header className="ak-header">
        <div className="ak-header-row">
          <div>
            <p className="ak-eyebrow">Administration · Accounts</p>
            <h1 className="ak-title">User Management</h1>
            <p className="ak-sub">Create, view, edit, and manage user accounts with location and position assignments.</p>
          </div>
          <div className="ak-header-aside">
            <button className="ak-btn ak-btn--primary" onClick={handleAdd}>+ Add New User</button>
          </div>
        </div>
      </header>

      {loading && <Loader label="Loading users" />}
```

New (no `AdminLayout`, no page header; a fragment root, a toolbar holds the Add button):
```jsx
  return (
    <>
      <div className="um-panel-toolbar">
        <button className="ak-btn ak-btn--primary" onClick={handleAdd}>+ Add New User</button>
      </div>

      {loading && <Loader label="Loading users" />}
```

- [ ] **Step 4: Close the new root correctly**

At the end of the `return`, the old markup closed with `</div>` (the `ak-page`) then `</AdminLayout>`. Find the tail that currently reads:
```jsx
        )}
      </div>
     </div>

      {/* Add/Edit User Modal */}
```
The first `</div>` closes `um-card`, the second `</div>` closed `ak-page`. Remove the `ak-page` closing `</div>` so the modal sits directly under the fragment:
```jsx
        )}
      </div>

      {/* Add/Edit User Modal */}
```
Then change the final closing from:
```jsx
      </Modal>
    </AdminLayout>
  );
};
```
to:
```jsx
      </Modal>
    </>
  );
};
```

- [ ] **Step 5: Add a minimal toolbar style**

Append to `frontend/src/admin/styles/user_management.css`:
```css
.um-panel-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}
```

- [ ] **Step 6: Rewrite `UserManagement.jsx` as a thin wrapper**

Replace the entire contents of `frontend/src/admin/pages/UserManagement.jsx` with:
```jsx
import React from 'react';
import AdminLayout from '../components/AdminLayout';
import UserManagementPanel from '../components/UserManagementPanel';

const UserManagement = () => (
  <AdminLayout>
    <div className="ak-page">
      <header className="ak-header">
        <div className="ak-header-row">
          <div>
            <p className="ak-eyebrow">Administration · Accounts</p>
            <h1 className="ak-title">User Management</h1>
            <p className="ak-sub">Create, view, edit, and manage user accounts with location and position assignments.</p>
          </div>
        </div>
      </header>
      <UserManagementPanel />
    </div>
  </AdminLayout>
);

export default UserManagement;
```

- [ ] **Step 7: Verify it compiles**

Run: `cd frontend && npx eslint src/admin/components/UserManagementPanel.jsx src/admin/pages/UserManagement.jsx`
Expected: no errors (warnings about pre-existing patterns are acceptable). If `eslint` is not directly runnable, instead run `npm run build` and expect a successful compile.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/components/UserManagementPanel.jsx frontend/src/admin/pages/UserManagement.jsx frontend/src/admin/styles/user_management.css
git commit -m "refactor(users): extract UserManagementPanel from UserManagement page"
```

---

## Task 2: Smoke test for `UserManagementPanel`

**Files:**
- Create: `frontend/src/admin/components/UserManagementPanel.test.jsx`

The panel fetches on mount via `batchAPI.get`, `usersAPI.getAll`, and `authAPI.getUser`, so the test mocks `../services/api`. We assert the panel renders its Add button without crashing.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/UserManagementPanel.test.jsx`:
```jsx
import { render, screen, waitFor } from '@testing-library/react';
import UserManagementPanel from './UserManagementPanel';

jest.mock('../services/api', () => ({
  usersAPI: {
    getAll: jest.fn().mockResolvedValue({ data: [], pagination: { total: 0, last_page: 1 } }),
  },
  authAPI: {
    getUser: jest.fn().mockResolvedValue({ data: { user_id: 1 } }),
  },
  batchAPI: {
    get: jest.fn().mockResolvedValue({ data: { roles: { data: [] }, locations: { data: [] } } }),
  },
}));

test('renders the Add New User action', async () => {
  render(<UserManagementPanel />);
  await waitFor(() =>
    expect(screen.getByRole('button', { name: /add new user/i })).toBeInTheDocument()
  );
});

test('shows empty state when there are no users', async () => {
  render(<UserManagementPanel />);
  await waitFor(() =>
    expect(screen.getByText(/no users found/i)).toBeInTheDocument()
  );
});
```

- [ ] **Step 2: Run the test**

Run: `cd frontend && npx react-scripts test src/admin/components/UserManagementPanel.test.jsx --watchAll=false`
Expected: both tests PASS. (If `confirmAction`/`toast` imports complain, they are not invoked on render and require no mock; if a CSS import fails under Jest, confirm CRA's default `moduleNameMapper` handles it — the existing `LocationBadge.test.jsx` proves CSS-importing trees test fine.)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/UserManagementPanel.test.jsx
git commit -m "test(users): smoke test UserManagementPanel render + empty state"
```

---

## Task 3: Swap the panel into System Configuration and clean up

**Files:**
- Modify: `frontend/src/admin/pages/SystemConfiguration.jsx`

- [ ] **Step 1: Import the panel**

Add to the import block near the top of `src/admin/pages/SystemConfiguration.jsx` (after the existing `Loader` import on line 3):
```jsx
import UserManagementPanel from '../components/UserManagementPanel';
```

- [ ] **Step 2: Replace the "User Accounts" card with the panel**

In the `activeTab === 'users'` block, delete the entire first card — from the comment `{/* User Accounts */}` and its `<div className="sc-card">` through its closing `</div>` that precedes `{/* Password Reset Requests */}`. Replace that whole card with:
```jsx
            {/* User Management (shared panel) */}
            <UserManagementPanel />
```
Leave the three following cards (Password Reset Requests, Active Reset Codes, Manual Password Reset) untouched.

- [ ] **Step 3: Remove now-unused table state**

Delete these state declarations (the old table's filters):
```jsx
  const [userRoleFilter, setUserRoleFilter] = useState('');
  const [userLocFilter, setUserLocFilter]  = useState('');
```

- [ ] **Step 4: Remove now-unused derived values**

Delete the `filteredUsers`, `roleOptions`, and `locationOptions` block:
```jsx
// Filter users based on selected role and location
  const filteredUsers = users.filter(u => {
    const userRole = u.role?.role_name || '';
    const userLocation = u.location?.location_name || '';
    const matchesRole = !userRoleFilter || userRole === userRoleFilter;
    const matchesLocation = !userLocFilter || userLocation === userLocFilter;
    return matchesRole && matchesLocation;
  });

  // Build role options for filter dropdown
  const roleOptions = roles.map(r => r.role_name).filter(Boolean);
  const locationOptions = locations.map(l => l.location_name).filter(Boolean);
```

Keep the `users`, `roles`, `locations`, `loading`, `error` state and the `fetchData` call — `users` still feeds the Manual Password Reset "Select User" dropdown. (`roles`/`locations` may now be unused by JSX; leaving them is harmless. If lint flags them as unused and fails the build, remove `roles`/`locations` from `fetchData`'s `Promise.all` and their `setRoles`/`setState` lines and the `useState` declarations — but do NOT remove `users`.)

- [ ] **Step 5: Hide the footer on the User Management tab**

The footer currently renders for `security | accounts | users | company`. Change its guard so the `users` tab is excluded. Old:
```jsx
        {(activeTab === 'security' || activeTab === 'accounts' || activeTab === 'users' || activeTab === 'company') && (
```
New:
```jsx
        {(activeTab === 'security' || activeTab === 'accounts' || activeTab === 'company') && (
```

- [ ] **Step 6: Verify compile + lint**

Run: `cd frontend && npm run build`
Expected: "Compiled successfully" (or compiled with only pre-existing warnings). If the build fails on `no-unused-vars` for `roles`/`locations`/`setRoles`/`setLocations`, apply the cleanup noted in Step 4 and rebuild.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/SystemConfiguration.jsx
git commit -m "feat(config): host shared UserManagementPanel in User Management tab"
```

---

## Task 4: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full frontend test suite**

Run: `cd frontend && npx react-scripts test --watchAll=false`
Expected: all tests pass, including the new `UserManagementPanel.test.jsx`.

- [ ] **Step 2: Manual smoke (dev server)**

Run: `cd frontend && npm start` (with the backend running per the project's `npm run dev`). Then verify in the browser:
- Sidebar → **User Management** (`/admin/users`): page looks the same; **+ Add New User** works; Edit / Deactivate / Archive / search / filters / pagination all function.
- Sidebar → **System Configuration** → **User Management** tab: the same full panel renders in place of the old static table; Add/Edit/Deactivate/Archive work; the three password-reset cards still appear below; the Manual Password Reset user dropdown is still populated; no "Save User Settings" footer bar shows on this tab.

- [ ] **Step 3: Confirm no regressions on other tabs**

In System Configuration, click **Security**, **User Accounts** (policy toggles), and **Company Settings** — each still shows its footer Save bar and saves as before.

---

## Notes for the implementer

- This is primarily a move-and-rewire refactor; the panel's internal logic is unchanged from the original page, so behavior parity is the bar.
- Do not alter `usersAPI` / `batchAPI` / `authAPI` or any backend route.
- The panel and the tab use different CSS namespaces (`um-*` vs `sc-*`), so embedding should not collide visually.
