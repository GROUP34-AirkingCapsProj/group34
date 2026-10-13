import { render, screen, fireEvent } from '@testing-library/react';
import BranchManagerDashboard from './BranchManagerDashboard';
import { batchAPI } from '../services/api';

const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: { role: { role_id: 3 } }, can: () => true }),
}));
jest.mock('../services/api', () => ({
  batchAPI: { get: jest.fn().mockResolvedValue({ data: { dashboard: {
    stats: { pending_prs: 2, pending_pos: 3, pending_transfers: 8 },
  } } }) },
}));
jest.mock('react-chartjs-2', () => ({ Line: () => null }));

test('branch manager sees PR and PO approval counts and working links', async () => {
  batchAPI.get.mockResolvedValue({ data: { dashboard: {
    stats: { pending_prs: 2, pending_pos: 3, pending_transfers: 8 },
  } } });
  render(<BranchManagerDashboard />);
  const prButton = await screen.findByRole('button', { name: 'Purchase requests (2)' });
  expect(screen.getByText('5')).toBeInTheDocument();
  fireEvent.click(prButton);
  expect(mockNavigate).toHaveBeenCalledWith('/purchase-requests', { state: { tab: 'approvals' } });
  fireEvent.click(screen.getByRole('button', { name: 'Purchase orders (3)' }));
  expect(mockNavigate).toHaveBeenCalledWith('/draft-po-creator');
  fireEvent.click(screen.getByRole('button', { name: 'Approvals' }));
  expect(mockNavigate).toHaveBeenCalledWith('/alerts');
});
