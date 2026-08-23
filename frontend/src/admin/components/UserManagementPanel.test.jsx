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

// The Resend button's disabled state mirrors two server-side guards. If it ever
// drifts, the admin gets an opaque 422 instead of a tooltip explaining why --
// or, worse, an enabled button on an account whose password Resend would
// destroy without being able to deliver the replacement.
describe('the Resend credentials button', () => {
  const row = (overrides) => ({
    user_id: 1,
    first_name: 'New',
    last_name: 'Hire',
    username: 'new_hire',
    email: 'new_hire@airking.com',
    recovery_email: 'new_hire@gmail.com',
    must_change_password: true,
    ...overrides,
  });

  const renderWith = async (user) => {
    usersAPI.getAll.mockResolvedValue({ data: [user], pagination: { total: 1, last_page: 1 } });
    render(<UserManagementPanel />);
    await waitFor(() => expect(screen.getByText('New Hire')).toBeInTheDocument());
    return screen.getByRole('button', { name: /resend/i });
  };

  test('is enabled for a user who has never completed first login', async () => {
    expect(await renderWith(row())).toBeEnabled();
  });

  test('is disabled once the user has set their own password', async () => {
    const button = await renderWith(row({ must_change_password: false }));
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('title', expect.stringMatching(/forgot password/i));
  });

  test('is disabled when the account has no recovery email to send to', async () => {
    // Seeded and legacy rows predate the recovery_email column but still carry
    // the default must_change_password = true, so they look eligible.
    const button = await renderWith(row({ recovery_email: null }));
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('title', expect.stringMatching(/no recovery email/i));
  });
});
