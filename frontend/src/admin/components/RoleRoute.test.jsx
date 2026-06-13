import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({
    // warehouse_personnel is not in ROUTE_ACCESS['/admin/items'] → no access
    user: { user_id: 1, role: { role_name: 'warehouse_personnel', role_id: 4 } },
  }),
}));

test('redirects to dashboard when the role cannot access the route', () => {
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
