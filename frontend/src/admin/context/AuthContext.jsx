import { createContext, useContext, useState, useCallback, useEffect } from 'react';
import { removeToken, authAPI, permissionsAPI } from '../services/api';

const AuthContext = createContext(null);

const readStoredUser = () => {
  try {
    return JSON.parse(sessionStorage.getItem('user') || 'null');
  } catch {
    return null;
  }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(readStoredUser);
  const [permissions, setPermissions] = useState(null);

  const updateUser = useCallback((userData) => {
    setUser(userData);
    if (userData) {
      sessionStorage.setItem('user', JSON.stringify(userData));
    } else {
      sessionStorage.removeItem('user');
    }
  }, []);

  const clearSession = useCallback(() => {
    updateUser(null);
    removeToken();
  }, [updateUser]);

  /**
   * Re-fetches the authenticated user from GET /api/user and updates the
   * stored session. This hydrates the location enforcement fields
   * (enforced_location_id, allowed_locations, has_strict_location, etc.)
   * that are not present in the login response.
   */
  const refreshUser = useCallback(async () => {
    try {
      const res = await authAPI.getUser();
      // Handle both response shapes: { success, data } and legacy { user }
      const fresh = res?.data ?? res?.user ?? null;
      if (fresh) updateUser(fresh);
      return fresh;
    } catch {
      return null;
    }
  }, [updateUser]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const refreshPermissions = useCallback(() => {
    if (!user?.user_id) return;
    permissionsAPI.getMine()
      .then((res) => setPermissions(res?.data ?? {}))
      .catch(() => setPermissions({}));
  }, [user?.user_id]);

  useEffect(() => {
    if (!user?.user_id) {
      setPermissions(null);
      return;
    }
    refreshPermissions();
  }, [user?.user_id]); // eslint-disable-line react-hooks/exhaustive-deps

  const can = useCallback(
    (feature, action) =>
      permissions != null && permissions[`${feature}.${action}`] === true,
    [permissions],
  );

  return (
    <AuthContext.Provider value={{ user, updateUser, refreshUser, clearSession, permissions, can, refreshPermissions }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
};

/**
 * Convenience hook — returns resolved location data for the current user.
 * Works with both login-populated users (fallback from location_id column)
 * and refreshUser()-hydrated users (full enforced_location_id fields).
 */
export const useUserLocation = () => {
  const { user } = useAuth();
  const roleId   = Number(user?.role?.role_id ?? 0);
  const roleName = user?.role?.role_name ?? '';

  // Prefer the server-computed flag; derive from role_id if not present yet
  const isStrictLocation = user?.has_strict_location ?? [2, 4].includes(roleId);

  // Admin (1) and branch_manager (3) may choose; all others are strict
  const canChooseLocation =
    !isStrictLocation &&
    (roleName === 'admin' || roleName === 'branch_manager' || roleId === 1 || roleId === 3);

  return {
    // The single location_id this user must transact at (null for admin = must pick)
    locationId:        user?.enforced_location_id ?? user?.location_id ?? null,
    locationName:      user?.primary_location?.location_name ?? user?.location?.location_name ?? null,
    locationType:      user?.primary_location?.location_type  ?? user?.location?.location_type  ?? null,
    isStrictLocation,
    // Populated after refreshUser() — array of { location_id, location_name, location_type, location_code }
    allowedLocations:  user?.allowed_locations ?? [],
    canChooseLocation,
  };
};
