import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';

// Mutable so each test can drive a different auth state through the same mock.
let mockAuth;
jest.mock('../context/AuthContext', () => ({
  useAuth: () => mockAuth,
}));

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/warranty-claims" element={<RoleRoute><div>Claim Page</div></RoleRoute>} />
        <Route path="/dashboard" element={<div>Dashboard Page</div>} />
      </Routes>
    </MemoryRouter>
  );

test('permission-gated route renders when warranty_claims.create is granted', () => {
  mockAuth = {
    user: { user_id: 1, role: { role_name: 'branch_manager', role_id: 3 } },
    permissions: { 'warranty_claims.create': true },
  };
  renderAt('/warranty-claims');
  expect(screen.getByText('Claim Page')).toBeInTheDocument();
});

test('permission-gated route redirects to dashboard without warranty_claims.create', () => {
  mockAuth = {
    user: { user_id: 2, role: { role_name: 'inventory_analyst', role_id: 2 } },
    permissions: { 'warranty_records.read': true },
  };
  renderAt('/warranty-claims');
  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
  expect(screen.queryByText('Claim Page')).not.toBeInTheDocument();
});

test('permission-gated route shows a checking state while permissions load', () => {
  mockAuth = {
    user: { user_id: 3, role: { role_name: 'branch_manager', role_id: 3 } },
    permissions: null,
  };
  renderAt('/warranty-claims');
  expect(screen.getByText('Checking access…')).toBeInTheDocument();
});
