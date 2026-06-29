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

  // Segmented toggle, rendered by the active panel in a right-aligned bar
  // below its header (each panel owns its own header).
  const switcher = (
    <div className="rcx-viewtoggle-bar">
      <div className="rcx-viewtoggle" role="group" aria-label="Access control view">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`rcx-viewtoggle-btn${activeKey === t.key ? ' is-active' : ''}`}
            onClick={() => { if (activeKey !== t.key) navigate(t.path); }}
          >
            {t.label}
          </button>
        ))}
      </div>
    </div>
  );

  return (
    <AdminLayout>
      {activeKey === 'roles'
        ? <RolePermissions tabSwitcher={switcher} />
        : <UserPermissionOverrides tabSwitcher={switcher} />}
    </AdminLayout>
  );
};

export default AccessControlPage;
