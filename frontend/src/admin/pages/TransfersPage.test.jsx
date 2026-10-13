import { render, screen, fireEvent } from '@testing-library/react';
import TransfersPage from './TransfersPage';
import { transfersAPI, locationsAPI } from '../services/api';

jest.mock('react-router-dom', () => ({ useNavigate: () => jest.fn(), useLocation: () => ({ search: '' }) }));
jest.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { user_id: 1, role: { role_name: 'admin' } }, can: () => true }) }));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../components/Modal', () => ({ children, isOpen }) => isOpen ? <div>{children}</div> : null);
jest.mock('../services/api', () => ({ transfersAPI: { getAll: jest.fn(), getById: jest.fn(), cancelRequest: jest.fn() }, locationsAPI: { getAll: jest.fn() } }));
jest.mock('./transfers/NewRequestModal', () => () => null);
jest.mock('./transfers/DispatchPanel', () => () => null);
jest.mock('./transfers/ReceivePanel', () => () => null);
jest.mock('./transfers/VerifyPanel', () => () => null);
jest.mock('../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

test('Rejected and Partial have their own tabs while Done contains successful transfers', async () => {
  const rows = ['Rejected', 'Partial', 'Received'].map((status, index) => ({
    transfer_id: index + 1, transfer_number: `TR-${status}`, intent: 'pull', requested_by: 1,
    status: { status_name: status }, transfer_date: '2026-10-13',
  }));
  transfersAPI.getAll.mockResolvedValue({ data: rows });
  rows[2].requested_by = { user_id: 1, first_name: 'Maria', last_name: 'Reyes', username: 'mreyes' };
  rows[2].approved_by = { user_id: 2, first_name: 'Juan', last_name: 'Cruz', username: 'jcruz' };
  transfersAPI.getById.mockResolvedValue({ data: rows[2] });
  locationsAPI.getAll.mockResolvedValue({ data: [] });
  render(<TransfersPage />);
  await screen.findByRole('button', { name: /Rejected.*1/ });
  fireEvent.click(screen.getByRole('button', { name: /Rejected.*1/ }));
  expect(screen.getByText('TR-Rejected')).toBeInTheDocument();
  expect(screen.queryByText('TR-Received')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /Partial.*1/ }));
  expect(screen.getByText('TR-Partial')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /Done.*1/ }));
  expect(screen.getByText('TR-Received')).toBeInTheDocument();
  expect(screen.queryByText('TR-Rejected')).not.toBeInTheDocument();
  expect(screen.queryByText('TR-Partial')).not.toBeInTheDocument();
  expect(screen.getByText('Maria Reyes')).toBeInTheDocument();
  expect(screen.getByText('Juan Cruz')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'View' }));
  await screen.findByRole('heading', { name: 'Lines' });
  expect(screen.getAllByText('Maria Reyes')).toHaveLength(2);
  expect(screen.getAllByText('Juan Cruz')).toHaveLength(2);
});
