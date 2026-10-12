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
        <Route path="/barcode-adjustment" element={<RoleRoute><div>Report Incident Page</div></RoleRoute>} />
        <Route path="/dashboard" element={<div>Dashboard Page</div>} />
      </Routes>
    </MemoryRouter>
  );

test('warehouse personnel reaches Report Incident with incident_reports.create', () => {
  mockAuth = {
    user: { user_id: 1, role: { role_name: 'warehouse_personnel', role_id: 4 } },
    permissions: { 'incident_reports.create': true, 'incident_reports.read': true },
  };
  renderAt('/barcode-adjustment');
  expect(screen.getByText('Report Incident Page')).toBeInTheDocument();
});

test('Report Incident redirects to dashboard without incident_reports.create', () => {
  mockAuth = {
    user: { user_id: 2, role: { role_name: 'auditor', role_id: 5 } },
    permissions: { 'incident_reports.read': true },
  };
  renderAt('/barcode-adjustment');
  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
  expect(screen.queryByText('Report Incident Page')).not.toBeInTheDocument();
});
