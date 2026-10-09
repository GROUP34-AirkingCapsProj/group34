import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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

test('opens the full profile photo, allows zooming, and closes with Escape', async () => {
  usersAPI.getAll.mockResolvedValue({ data: [{ user_id: 1, first_name: 'Ada', last_name: 'Lovelace',
    avatar_url: '/storage/avatars/ada.png' }], pagination: { total: 1, last_page: 1 } });
  render(<UserManagementPanel />);
  fireEvent.click(await screen.findByRole('button', { name: 'View profile photo of Ada Lovelace' }));
  expect(screen.getByRole('img', { name: 'Ada Lovelace full profile photo' })).toHaveStyle({ objectFit: 'contain' });
  fireEvent.click(screen.getByRole('button', { name: 'Zoom in' }));
  expect(screen.getByText('150%')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Reset zoom' }));
  expect(screen.getByText('100%')).toBeInTheDocument();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

test('shows initials and disables preview when the profile image fails to load', async () => {
  usersAPI.getAll.mockResolvedValue({ data: [{ user_id: 1, first_name: 'Ada', last_name: 'Lovelace',
    avatar_url: '/storage/avatars/missing.png' }], pagination: { total: 1, last_page: 1 } });
  render(<UserManagementPanel />);
  fireEvent.error(await screen.findByRole('img', { name: 'Ada Lovelace profile photo' }));
  expect(screen.getByText('AL')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View profile photo of Ada Lovelace' })).toBeDisabled();
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

/**
 * The edit form's password field posts to PUT /users/{id}, which enforces the
 * app's full password policy. The form used to check length only, so an admin
 * resetting someone's password filled it in, waited for the request, and got a
 * 422 back for a rule nothing on screen had stated.
 */
describe('the edit form password field', () => {
  const row = {
    user_id: 7,
    first_name: 'New',
    last_name: 'Hire',
    username: 'new_hire',
    email: 'new_hire@airking.com',
    recovery_email: 'new_hire@gmail.com',
    must_change_password: false,
    role_id: 1,
    status_id: 1,
  };

  const openEditForm = async () => {
    usersAPI.getAll.mockResolvedValue({ data: [row], pagination: { total: 1, last_page: 1 } });
    usersAPI.update = jest.fn().mockResolvedValue({ data: {} });
    render(<UserManagementPanel />);
    await waitFor(() => expect(screen.getByText('New Hire')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /^edit$/i }));
    return screen.getByLabelText(/new password/i);
  };

  test('rejects a password that meets the length but not the policy', async () => {
    const field = await openEditForm();
    fireEvent.change(field, { target: { value: 'weakpassword' } });
    fireEvent.click(screen.getByRole('button', { name: /update user/i }));

    await waitFor(() =>
      expect(screen.getByText(/upper and lower case, a number, and a symbol/i)).toBeInTheDocument()
    );
    expect(usersAPI.update).not.toHaveBeenCalled();
  });

  test('submits a policy-compliant password to the API', async () => {
    const field = await openEditForm();
    fireEvent.change(field, { target: { value: 'Brand!New9Pass' } });
    fireEvent.change(screen.getByLabelText(/confirm password/i), {
      target: { value: 'Brand!New9Pass' },
    });
    fireEvent.click(screen.getByRole('button', { name: /update user/i }));

    await waitFor(() => expect(usersAPI.update).toHaveBeenCalled());
    expect(usersAPI.update.mock.calls[0][1]).toMatchObject({
      password: 'Brand!New9Pass',
      password_confirmation: 'Brand!New9Pass',
    });
  });
});

/**
 * Editing your own account through this panel is the one password change that
 * still costs the current password -- the server demands it, so the form has to
 * collect it rather than letting the admin submit into a 422.
 */
describe('editing your own account', () => {
  const self = {
    user_id: 1,
    first_name: 'Ad',
    last_name: 'Min',
    username: 'admin',
    email: 'admin@airking.com',
    recovery_email: 'admin@gmail.com',
    must_change_password: false,
    role_id: 1,
    status_id: 1,
  };

  const openOwnEditForm = async () => {
    usersAPI.getAll.mockResolvedValue({ data: [self], pagination: { total: 1, last_page: 1 } });
    usersAPI.update = jest.fn().mockResolvedValue({ data: {} });
    render(<UserManagementPanel />);
    await waitFor(() => expect(screen.getByText('Ad Min')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /^edit$/i }));
    const field = screen.getByLabelText(/new password/i);
    fireEvent.change(field, { target: { value: 'Brand!New9Pass' } });
    fireEvent.change(screen.getByLabelText(/confirm password/i), {
      target: { value: 'Brand!New9Pass' },
    });
  };

  test('asks for the current password before submitting', async () => {
    await openOwnEditForm();
    fireEvent.click(screen.getByRole('button', { name: /update user/i }));

    await waitFor(() =>
      expect(screen.getByText(/enter your current password/i)).toBeInTheDocument()
    );
    expect(usersAPI.update).not.toHaveBeenCalled();
  });

  test('sends the current password along with the new one', async () => {
    await openOwnEditForm();
    fireEvent.change(screen.getByLabelText(/your current password/i), {
      target: { value: 'Secret123!' },
    });
    fireEvent.click(screen.getByRole('button', { name: /update user/i }));

    await waitFor(() => expect(usersAPI.update).toHaveBeenCalled());
    expect(usersAPI.update.mock.calls[0][1]).toMatchObject({
      current_password: 'Secret123!',
      password: 'Brand!New9Pass',
    });
  });
});
