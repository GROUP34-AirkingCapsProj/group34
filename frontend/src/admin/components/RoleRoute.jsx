import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { canAccessRoute } from '../utils/roles';
import { NAV_BUNDLES, bundleVisible, PERMISSION_GATED_ROUTES } from '../utils/navBundles';
import { ROLE_OPEN_PATHS } from '../utils/sidebarBuilder';

const RoleRoute = ({ children }) => {
  const location = useLocation();
  const { user, permissions } = useAuth();

  if (!user) return <Navigate to="/admin/login" replace />;

  const bundle = NAV_BUNDLES.find((b) => b.routePath === location.pathname);
  if (bundle) {
    if (permissions == null) {
      return <div style={{ padding: '1.5rem', color: '#6b7280' }}>Checking access…</div>;
    }
    if (!bundleVisible(bundle, permissions)) return <Navigate to="/admin/dashboard" replace />;
    return children;
  }

  // Permission-gated routes that are intentionally not sidebar bundles
  // (e.g. /admin/warranty-claims, reached via the Warranty Records button).
  const requiredPerm = PERMISSION_GATED_ROUTES[location.pathname];
  if (requiredPerm) {
    if (permissions == null) {
      return <div style={{ padding: '1.5rem', color: '#6b7280' }}>Checking access…</div>;
    }
    if (permissions[requiredPerm] !== true) return <Navigate to="/admin/dashboard" replace />;
    return children;
  }

  if (ROLE_OPEN_PATHS.has(location.pathname)) return children;
  if (!canAccessRoute(user, location.pathname)) return <Navigate to="/admin/dashboard" replace />;
  return children;
};

export default RoleRoute;
