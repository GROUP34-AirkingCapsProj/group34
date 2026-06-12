import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({
    user: { user_id: 1, role: { role_name: 'warehouse_personnel', role_id: 4 } },
    permissions: {},          // loaded but empty — no perms → no access to /admin/items
    can: () => false,
    refreshPermissions: jest.fn(),
  }),
}));

test('redirects to dashboard when required permission missing', () => {
  render(
    <MemoryRouter initialEntries={['/admin/items']}>
      <Routes>
        <Route path="/admin/items" element={<RoleRoute><div>Items Page</div></RoleRoute>} />
        <Route path="/admin/dashboard" element={<div>Dashboard Page</div>} />
      </Routes>
    </MemoryRouter>
  );
  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
  expect(screen.queryByText('Items Page')).not.toBeInTheDocument();
});
