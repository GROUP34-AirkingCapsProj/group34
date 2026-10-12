import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({
    // warehouse_personnel hitting /items: has a nav bundle (item_master_management.read)
    // permissions loaded but empty → bundleVisible returns false → redirect to dashboard
    user: { user_id: 1, role: { role_name: 'warehouse_personnel', role_id: 4 } },
    permissions: {},
  }),
}));

test('redirects to dashboard when the role cannot access the route', () => {
  render(
    <MemoryRouter initialEntries={['/items']}>
      <Routes>
        <Route path="/items" element={<RoleRoute><div>Items Page</div></RoleRoute>} />
        <Route path="/dashboard" element={<div>Dashboard Page</div>} />
      </Routes>
    </MemoryRouter>
  );
  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
  expect(screen.queryByText('Items Page')).not.toBeInTheDocument();
});
