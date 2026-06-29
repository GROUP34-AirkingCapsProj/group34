import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import RolePermissions from './RolePermissions';
import UserPermissionOverrides from './UserPermissionOverrides';
import '../styles/rbac_console.css';

const TABS = [
  { key: 'roles',     label: 'Role Permissions', path: '/admin/roles' },
  { key: 'overrides', label: 'User Overrides',   path: '/admin/user-permissions' },
];

const AccessControlPage = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const activeKey = location.pathname === '/admin/user-permissions' ? 'overrides' : 'roles';

  return (
    <AdminLayout>
      <div className="rcx-toptabs">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`rcx-toptab${activeKey === t.key ? ' is-active' : ''}`}
            onClick={() => { if (activeKey !== t.key) navigate(t.path); }}
          >
            {t.label}
          </button>
        ))}
      </div>
      {activeKey === 'roles' ? <RolePermissions /> : <UserPermissionOverrides />}
    </AdminLayout>
  );
};

export default AccessControlPage;
