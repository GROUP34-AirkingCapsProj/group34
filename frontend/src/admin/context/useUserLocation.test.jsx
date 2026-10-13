import { render } from '@testing-library/react';
import { AuthProvider, useUserLocation } from './AuthContext';

jest.mock('../services/api', () => ({
  removeToken: jest.fn(),
  authAPI: { getUser: jest.fn(() => Promise.resolve(null)) },
  permissionsAPI: { mine: jest.fn(() => Promise.resolve({ data: {} })) },
}));

/**
 * useUserLocation reports two boundaries that disagree for the branch manager:
 * it reads every location (readsAllLocations) and writes only at its own
 * (isStrictLocation). These tests cover both the server-sent flags and the
 * role_id fallback used before refreshUser() hydrates the session.
 */
const probe = (user) => {
  let seen;
  const Probe = () => {
    seen = useUserLocation();
    return null;
  };

  sessionStorage.setItem('user', JSON.stringify(user));
  render(
    <AuthProvider>
      <Probe />
    </AuthProvider>,
  );

  return seen;
};

afterEach(() => {
  sessionStorage.clear();
});

describe('useUserLocation scope flags', () => {
  test('prefers the server flags: a branch manager reads everywhere and writes at one branch', () => {
    const scope = probe({
      role: { role_id: 3, role_name: 'branch_manager' },
      location_id: 7,
      enforced_location_id: 7,
      has_strict_location: true,
      reads_all_locations: true,
    });

    expect(scope.readsAllLocations).toBe(true);
    expect(scope.isStrictLocation).toBe(true);
    // Strict means there is nothing to pick: the server pins the location.
    expect(scope.canChooseLocation).toBe(false);
    expect(scope.locationId).toBe(7);
  });

  test('warehouse personnel is scoped on both sides', () => {
    const scope = probe({
      role: { role_id: 4, role_name: 'warehouse_personnel' },
      location_id: 4,
      has_strict_location: true,
      reads_all_locations: false,
    });

    expect(scope.readsAllLocations).toBe(false);
    expect(scope.isStrictLocation).toBe(true);
    expect(scope.canChooseLocation).toBe(false);
  });

  test('the admin reads everywhere and chooses where it writes', () => {
    const scope = probe({
      role: { role_id: 1, role_name: 'admin' },
      has_strict_location: false,
      reads_all_locations: true,
    });

    expect(scope.readsAllLocations).toBe(true);
    expect(scope.isStrictLocation).toBe(false);
    expect(scope.canChooseLocation).toBe(true);
  });

  describe('role_id fallback, before refreshUser() hydrates the flags', () => {
    test.each([
      [1, true, false],
      [2, true, false],
      [3, true, true],
      [4, false, true],
      [5, true, false],
    ])('role %i → readsAll %s, strict %s', (roleId, readsAll, strict) => {
      const scope = probe({ role: { role_id: roleId }, location_id: 2 });

      expect(scope.readsAllLocations).toBe(readsAll);
      expect(scope.isStrictLocation).toBe(strict);
    });
  });
});
