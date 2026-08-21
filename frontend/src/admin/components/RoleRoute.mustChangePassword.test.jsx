import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';

const mockAuth = { user: null, permissions: {} };

jest.mock('../context/AuthContext', () => ({
  useAuth: () => mockAuth,
}));

// Isolate the must_change_password guard from RoleRoute's other gates. Without
// these, a pass-through case would still be redirected by the nav-bundle /
// permission checks and the test would pass or fail for the wrong reason.
jest.mock('../utils/navBundles', () => ({
  NAV_BUNDLES: [],
  PERMISSION_GATED_ROUTES: {},
  bundleVisible: () => true,
}));
jest.mock('../utils/roles', () => ({
  canAccessRoute: () => true,
}));
jest.mock('../utils/sidebarBuilder', () => ({
  ROLE_OPEN_PATHS: new Set(),
}));

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/dashboard" element={<RoleRoute><div>Dashboard Page</div></RoleRoute>} />
        <Route path="/admin/change-password" element={<div>Change Password Page</div>} />
        <Route path="/admin/login" element={<div>Login Page</div>} />
      </Routes>
    </MemoryRouter>
  );

const admin = { user_id: 1, role: { role_name: 'admin', role_id: 1 } };

test('a user holding a temporary password is sent to the change-password screen', () => {
  mockAuth.user = { ...admin, must_change_password: true };

  renderAt('/admin/dashboard');

  expect(screen.getByText('Change Password Page')).toBeInTheDocument();
  expect(screen.queryByText('Dashboard Page')).not.toBeInTheDocument();
});

test('a user who has chosen their own password passes through', () => {
  mockAuth.user = { ...admin, must_change_password: false };

  renderAt('/admin/dashboard');

  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
});

test('a user payload without the field at all is not gated', () => {
  // Legacy sessions cached in sessionStorage predate the column. `undefined` is
  // falsy, so the guard must not fire — otherwise upgrading the app would bounce
  // every already-signed-in user to the change-password screen.
  mockAuth.user = { ...admin };

  renderAt('/admin/dashboard');

  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
});
