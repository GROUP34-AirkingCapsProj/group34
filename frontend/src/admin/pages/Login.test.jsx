import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import Login from './Login';
import { authAPI } from '../services/api';

// Mocks match the real file: Login.jsx imports authAPI, useAuth from
// ../context/AuthContext, toast from ../utils/toast, and fetches the CAPTCHA
// via authAPI.getLoginChallenge().
jest.mock('../services/api', () => ({
  authAPI: {
    login: jest.fn(),
    getLoginChallenge: jest.fn(),
    submitRecoveryEmail: jest.fn(),
    verifyOtp: jest.fn(),
  },
}));

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ updateUser: jest.fn() }),
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn() },
}));

const renderLogin = () => render(<MemoryRouter><Login /></MemoryRouter>);

// The email input has a label with no htmlFor, so it is queried by placeholder.
// The password field renders through PasswordFieldWithToggle, which does pass
// its id through, so its label association works.
const submitCredentials = async () => {
  await userEvent.type(screen.getByPlaceholderText('name@example.com'), 'admin@airking.com');
  await userEvent.type(screen.getByLabelText('Password'), 'Secret123!');
  await userEvent.click(screen.getByRole('button', { name: /^login$/i }));
};

beforeEach(() => {
  authAPI.getLoginChallenge.mockResolvedValue({
    data: { captcha_token: 'tok', question: 'What is 2 + 3?', operand_a: 2, operand_b: 3 },
  });
});

test('asks for a recovery email when the API reports one is missing', async () => {
  authAPI.login.mockResolvedValue({
    requires_recovery_email: true,
    email: 'admin@airking.com',
  });

  renderLogin();
  await waitFor(() => expect(authAPI.getLoginChallenge).toHaveBeenCalled());
  await submitCredentials();

  await waitFor(() =>
    expect(screen.getByLabelText(/recovery email/i)).toBeInTheDocument()
  );

  // The credential fields give way to the new step, so the user cannot submit
  // the login form again while the account is half-way through.
  expect(screen.queryByPlaceholderText('name@example.com')).not.toBeInTheDocument();
});

test('shows the code entry screen when the API reports a code was sent', async () => {
  authAPI.login.mockResolvedValue({
    requires_otp: true,
    email: 'admin@airking.com',
  });

  renderLogin();
  await waitFor(() => expect(authAPI.getLoginChallenge).toHaveBeenCalled());
  await submitCredentials();

  await waitFor(() =>
    expect(screen.getByLabelText(/verification code/i)).toBeInTheDocument()
  );
});

test('saving a recovery email moves the user on to the code entry screen', async () => {
  authAPI.login.mockResolvedValue({
    requires_recovery_email: true,
    email: 'admin@airking.com',
  });
  authAPI.submitRecoveryEmail.mockResolvedValue({
    requires_otp: true,
    email: 'admin@airking.com',
  });

  renderLogin();
  await waitFor(() => expect(authAPI.getLoginChallenge).toHaveBeenCalled());
  await submitCredentials();

  const field = await screen.findByLabelText(/recovery email/i);
  await userEvent.type(field, 'personal@gmail.com');
  await userEvent.click(screen.getByRole('button', { name: /save and send code/i }));

  // The password is replayed from memory rather than retyped -- the server
  // re-verifies it, so it has to reach the endpoint.
  await waitFor(() =>
    expect(authAPI.submitRecoveryEmail).toHaveBeenCalledWith(
      'admin@airking.com',
      'Secret123!',
      'personal@gmail.com'
    )
  );

  await waitFor(() =>
    expect(screen.getByLabelText(/verification code/i)).toBeInTheDocument()
  );
});
