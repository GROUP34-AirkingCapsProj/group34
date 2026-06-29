import { render, screen, waitFor } from '@testing-library/react';
import UserManagementPanel from './UserManagementPanel';
import { usersAPI, authAPI, batchAPI } from '../services/api';

jest.mock('../services/api', () => ({
  usersAPI: { getAll: jest.fn() },
  authAPI: { getUser: jest.fn() },
  batchAPI: { get: jest.fn() },
  resolveStorageUrl: (path) => path,
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

test('renders a profile photo when avatar_url is set, initials otherwise', async () => {
  usersAPI.getAll.mockResolvedValue({
    data: [
      { user_id: 1, first_name: 'Ada', last_name: 'Lovelace', username: 'ada', avatar_url: '/storage/avatars/ada.png' },
      { user_id: 2, first_name: 'Grace', last_name: 'Hopper', username: 'grace', avatar_url: null },
    ],
    pagination: { total: 2, last_page: 1 },
  });

  const { container } = render(<UserManagementPanel />);

  // Ada has a photo -> an <img> is rendered inside her avatar.
  await waitFor(() => expect(screen.getByText('Ada Lovelace')).toBeInTheDocument());
  expect(container.querySelector('.um-avatar img')).toBeInTheDocument();

  // Grace has no photo -> her initials are shown instead.
  expect(screen.getByText('GH')).toBeInTheDocument();
});
