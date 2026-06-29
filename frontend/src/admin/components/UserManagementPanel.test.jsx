import { render, screen, waitFor } from '@testing-library/react';
import UserManagementPanel from './UserManagementPanel';
import { usersAPI, authAPI, batchAPI } from '../services/api';

jest.mock('../services/api', () => ({
  usersAPI: { getAll: jest.fn() },
  authAPI: { getUser: jest.fn() },
  batchAPI: { get: jest.fn() },
}));

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test — so configure the mocks here.
beforeEach(() => {
  usersAPI.getAll.mockResolvedValue({ data: [], pagination: { total: 0, last_page: 1 } });
  authAPI.getUser.mockResolvedValue({ data: { user_id: 1 } });
  batchAPI.get.mockResolvedValue({ data: { roles: { data: [] }, locations: { data: [] } } });
});

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
