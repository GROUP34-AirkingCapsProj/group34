import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { canAccessRoute } from '../utils/roles';
import { NAV_BUNDLES, bundleVisible, PERMISSION_GATED_ROUTES } from '../utils/navBundles';
import { ROLE_OPEN_PATHS } from '../utils/sidebarBuilder';

const RoleRoute = ({ children }) => {
  const location = useLocation();
  const { user, permissions } = useAuth();

  if (!user) return <Navigate to="/login" replace />;

  // Temporary password still in place — every other API route returns 403, so
  // send the user to the one screen that can clear it. The middleware is the
  // real enforcement; this just avoids rendering pages that cannot load data.
  if (user.must_change_password) return <Navigate to="/change-password" replace />;

  const bundle = NAV_BUNDLES.find((b) => b.routePath === location.pathname);
  if (bundle) {
    // Dashboard is the post-login landing page — render immediately while
    // permissions hydrate so we don't sit on "Checking access…". Widgets already
    // gate themselves with can().
    if (permissions == null) {
      if (location.pathname === '/dashboard') return children;
      return <div style={{ padding: '1.5rem', color: 'var(--text-muted)' }}>Checking access…</div>;
    }
    if (!bundleVisible(bundle, permissions)) return <Navigate to="/dashboard" replace />;
    return children;
  }

  // Permission-gated routes that are intentionally not sidebar bundles
  // (e.g. /warranty-claims, reached via the Warranty Records button).
  const requiredPerm = PERMISSION_GATED_ROUTES[location.pathname];
  if (requiredPerm) {
    if (permissions == null) {
      return <div style={{ padding: '1.5rem', color: 'var(--text-muted)' }}>Checking access…</div>;
    }
    if (permissions[requiredPerm] !== true) return <Navigate to="/dashboard" replace />;
    return children;
  }

  if (ROLE_OPEN_PATHS.has(location.pathname)) return children;
  if (!canAccessRoute(user, location.pathname)) return <Navigate to="/dashboard" replace />;
  return children;
};

export default RoleRoute;
