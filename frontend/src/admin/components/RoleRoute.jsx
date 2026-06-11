import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { canAccessRoute, ROUTE_PERMISSION } from '../utils/roles';

const RoleRoute = ({ children }) => {
  const location = useLocation();
  const { user, permissions, can } = useAuth();

  if (!user) {
    return <Navigate to="/admin/login" replace />;
  }

  if (!canAccessRoute(user, location.pathname)) {
    // The static role list doesn't allow this route — but it may be unlockable by a
    // live DB permission (e.g. a granted scan-barcode permission).
    const permReq = ROUTE_PERMISSION[location.pathname];
    if (permReq) {
      if (permissions == null) {
        // Permissions haven't loaded yet; wait rather than redirect.
        return <div style={{ padding: '1.5rem', color: '#6b7280' }}>Checking access…</div>;
      }
      if (!can(permReq[0], permReq[1])) {
        return <Navigate to="/admin/dashboard" replace />;
      }
    } else {
      return <Navigate to="/admin/dashboard" replace />;
    }
  }

  return children;
};

export default RoleRoute;

