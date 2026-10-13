import { render, screen } from '@testing-library/react';
import { AuthProvider, useAuth } from './AuthContext';
import { permissionsAPI } from '../services/api';
import { writeCachedPermissions } from '../utils/permissionCache';

jest.mock('../services/api', () => ({
  removeToken: jest.fn(), authAPI: {}, permissionsAPI: { getMine: jest.fn() },
}));

function ApprovalAccess() {
  const { can } = useAuth();
  return <div>{can('purchase_request_approval', 'approve') ? 'Approvals available' : 'Approvals hidden'}</div>;
}

test('revalidates a fresh cached denial when the session opens', async () => {
  sessionStorage.setItem('user', JSON.stringify({ user_id: 4 }));
  writeCachedPermissions({ 'purchase_request_approval.approve': false }, Date.now());
  permissionsAPI.getMine.mockResolvedValue({ data: { 'purchase_request_approval.approve': true } });
  render(<AuthProvider><ApprovalAccess /></AuthProvider>);
  expect(await screen.findByText('Approvals available')).toBeInTheDocument();
  expect(permissionsAPI.getMine).toHaveBeenCalledTimes(1);
  sessionStorage.clear();
});
