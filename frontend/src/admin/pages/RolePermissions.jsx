import React, { useState, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { batchAPI } from '../services/api';
import '../styles/role_permissions.css';

const RolePermissions = () => {
  const [roles, setRoles] = useState([]);
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchRoles = async () => {
    try {
      setLoading(true);
      const res = await batchAPI.get({ include: ['roles', 'users'] });
      const data = res?.data ?? {};
      setRoles(Array.isArray(data.roles?.data) ? data.roles.data : []);
      setUsers(Array.isArray(data.users?.data) ? data.users.data : []);
      setError(null);
    } catch (err) {
      setError(err.message);
      console.error('Failed to fetch roles:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRoles();
  }, []);

  const getUsersCountForRole = (roleId) => {
    return users.filter(u => u.role_id === roleId).length;
  };

  const getPermissionLevel = (roleName) => {
    const name = (roleName || '').toLowerCase();
    if (name.includes('admin')) return 'Full Access';
    if (name.includes('analyst')) return 'Analytics & Reporting';
    if (name.includes('manager')) return 'Branch Oversight';
    if (name.includes('warehouse') || name.includes('personnel')) return 'Inventory Operations';
    if (name.includes('auditor')) return 'Read-Only Audit';
    return 'Standard Access';
  };

  const handleManagePermissions = (roleId) => {
    console.log('Manage permissions for role:', roleId);
  };

  return (
    <AdminLayout>
      <div className="role-permissions-content">
        <div className="rp-page-header">
          <h1>Role & Permissions</h1>
          <p>Define and assign roles and permissions for RBAC</p>
        </div>

        {loading && <div className="loading-message">Loading roles...</div>}
        {error && <div className="error-message">Error: {error}</div>}

        <div className="roles-grid">
          {roles.map((role) => (
            <div key={role.role_id} className="role-card">
              <div className="role-card-header">
                <h3 className="role-name">{role.description || role.role_name}</h3>
                <span className="users-assigned">{getUsersCountForRole(role.role_id)} users assigned</span>
              </div>

              <div className="role-card-body">
                <span className="permission-label">Permission Level</span>
                <span className="permission-value">{getPermissionLevel(role.role_name)}</span>
              </div>

              <button
                className="manage-permissions-btn"
                onClick={() => handleManagePermissions(role.role_id)}
              >
                Manage Permissions
              </button>
            </div>
          ))}
        </div>
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
