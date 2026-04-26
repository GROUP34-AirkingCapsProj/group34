import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { canAccessRoute } from '../utils/roles';

const RoleRoute = ({ children }) => {
  const location = useLocation();
  const { user } = useAuth();

  if (!user) {
    return <Navigate to="/admin/login" replace />;
  }

  if (!canAccessRoute(user, location.pathname)) {
    return <Navigate to="/admin/dashboard" replace />;
  }

  return children;
};

export default RoleRoute;

