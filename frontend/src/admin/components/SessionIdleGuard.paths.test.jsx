import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SessionIdleGuard from './SessionIdleGuard';

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ clearSession: jest.fn() }),
}));

jest.mock('../services/api', () => ({
  authAPI: { logout: jest.fn() },
  getSessionTimeoutMinutes: () => 15,
  getToken: () => 'a-token',
  SESSION_TIMEOUT_CHANGED_EVENT: 'session-timeout-changed',
}));

jest.mock('../utils/toast', () => ({ toast: { warning: jest.fn() } }));

// The guard arms itself by subscribing to activity events, so a mousedown
// listener is the observable proof that it is active on a given path.
function armedAt(pathname) {
  const addSpy = jest.spyOn(window, 'addEventListener');
  render(
    <MemoryRouter initialEntries={[pathname]}>
      <SessionIdleGuard />
    </MemoryRouter>
  );
  const armed = addSpy.mock.calls.some(([event]) => event === 'mousedown');
  addSpy.mockRestore();
  return armed;
}

afterEach(() => jest.clearAllMocks());

test('arms on an unprefixed protected route', () => {
  expect(armedAt('/dashboard')).toBe(true);
});

test('arms on a nested protected route', () => {
  expect(armedAt('/ist/42/receive')).toBe(true);
});

test('arms on the change-password screen', () => {
  // Protected today: it is reachable only with a valid session.
  expect(armedAt('/change-password')).toBe(true);
});

test('does not arm on the login screen', () => {
  expect(armedAt('/login')).toBe(false);
});

test('does not arm on the forgot-password screen', () => {
  expect(armedAt('/forgot-password')).toBe(false);
});

test('does not arm on the root redirect', () => {
  expect(armedAt('/')).toBe(false);
});

test('does not arm on a Supabase PWA route', () => {
  // The PWA has its own Supabase session handling.
  expect(armedAt('/pwa/dashboard')).toBe(false);
});
