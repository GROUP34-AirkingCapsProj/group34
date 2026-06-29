import React from 'react';
import AdminLayout from '../components/AdminLayout';
import UserManagementPanel from '../components/UserManagementPanel';

const UserManagement = () => (
  <AdminLayout>
    <div className="ak-page">
      <header className="ak-header">
        <div className="ak-header-row">
          <div>
            <p className="ak-eyebrow">Administration · Accounts</p>
            <h1 className="ak-title">User Management</h1>
            <p className="ak-sub">Create, view, edit, and manage user accounts with location and position assignments.</p>
          </div>
        </div>
      </header>
      <UserManagementPanel />
    </div>
  </AdminLayout>
);

export default UserManagement;
