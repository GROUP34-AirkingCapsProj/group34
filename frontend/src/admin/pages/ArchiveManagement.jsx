import React, { useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { toast } from '../utils/toast';
import '../styles/archive_management.css';

const ArchiveManagement = () => {
  const [searchCode, setSearchCode] = useState('');
  const [searchType, setSearchType] = useState('');
  const [archiveSettings, setArchiveSettings] = useState({
    autoArchiveDays: '365',
    retentionYears: '',
    compressArchives: true,
    backupArchives: true,
  });

  const stats = {
    archivedTransactions: 0,
    archivedUsers: 0,
    archiveSize: '0 MB',
  };

  const archivedTransactions = [];

  const archivedUsers = [];

  const handleSettingsChange = (field, value) => {
    setArchiveSettings(prev => ({ ...prev, [field]: value }));
  };

  const handleToggle = (field) => {
    setArchiveSettings(prev => ({ ...prev, [field]: !prev[field] }));
  };

  return (
    <AdminLayout>
      <div className="am-content">
        {/* Page Header */}
        <div className="am-page-header">
          <h1>Archive Management</h1>
          <p>Historical records and archived data for long-term record keeping</p>
        </div>

        {/* Stat Cards */}
        <div className="am-stats-grid">
          <div className="am-stat-card">
            <div className="am-stat-icon blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="21 8 21 21 3 21 3 8"></polyline>
                <rect x="1" y="3" width="22" height="5"></rect>
                <line x1="10" y1="12" x2="14" y2="12"></line>
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Archived Transactions</span>
              <span className="am-stat-value">{stats.archivedTransactions.toLocaleString()}</span>
            </div>
          </div>
          <div className="am-stat-card">
            <div className="am-stat-icon red">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Archived Users</span>
              <span className="am-stat-value">{stats.archivedUsers}</span>
            </div>
          </div>
          <div className="am-stat-card">
            <div className="am-stat-icon purple">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Archive Size</span>
              <span className="am-stat-value">{stats.archiveSize}</span>
            </div>
          </div>
        </div>

        {/* Archived Transactions */}
        <div className="am-section">
          <div className="am-section-header">
            <h2>Archived Transactions</h2>
            <p>Historical transaction records</p>
          </div>

          <div className="am-search-bar">
            <input
              type="text"
              placeholder="Search by code..."
              value={searchCode}
              onChange={(e) => setSearchCode(e.target.value)}
            />
            <input
              type="text"
              placeholder="Filter by type..."
              value={searchType}
              onChange={(e) => setSearchType(e.target.value)}
            />
            <button className="am-btn-search">Search Archive</button>
          </div>

          <div className="am-archive-list">
            {archivedTransactions.map((archive) => (
              <div className="am-archive-row" key={archive.id}>
                <div className="am-archive-info">
                  <span className="am-archive-id">{archive.id}</span>
                  <span className="am-archive-desc">{archive.name} • {archive.records} records</span>
                </div>
                <div className="am-archive-meta">
                  <span className="am-archive-date">{archive.date}</span>
                  <span className="am-archive-size">{archive.size}</span>
                </div>
                <div className="am-archive-actions">
                  <button className="am-btn-action view">View</button>
                  <button className="am-btn-action restore">Restore</button>
                  <button className="am-btn-action delete">Delete</button>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Archived Users */}
        <div className="am-section">
          <div className="am-section-header">
            <h2>Archived Users</h2>
            <p>Inactive and deleted user accounts</p>
          </div>

          <div className="am-table-wrapper">
            <table className="am-table">
              <thead>
                <tr>
                  <th>EMPLOYEE ID</th>
                  <th>NAME</th>
                  <th>ROLE</th>
                  <th>BRANCH</th>
                  <th>ARCHIVED DATE</th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                {archivedUsers.map((user) => (
                  <tr key={user.employeeId}>
                    <td>{user.employeeId}</td>
                    <td>{user.name}</td>
                    <td>{user.role}</td>
                    <td>{user.branch}</td>
                    <td>{user.archivedDate}</td>
                    <td>
                      <button className="am-btn-action restore">Restore</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Archive Settings */}
        <div className="am-section">
          <div className="am-section-header">
            <h2>Archive Settings</h2>
            <p>Configure archive retention policies</p>
          </div>

          <div className="am-settings-grid">
            <div className="am-settings-left">
              <div className="am-field">
                <label>Auto-Archive After (Days)</label>
                <input
                  type="number"
                  value={archiveSettings.autoArchiveDays}
                  onChange={(e) => handleSettingsChange('autoArchiveDays', e.target.value)}
                />
                <span className="am-field-hint">Records older than this will be automatically archived</span>
              </div>
              <div className="am-field">
                <label>Retention Period (Years)</label>
                <input
                  type="number"
                  value={archiveSettings.retentionYears}
                  onChange={(e) => handleSettingsChange('retentionYears', e.target.value)}
                  placeholder=""
                />
                <span className="am-field-hint">How long to keep archived data</span>
              </div>
            </div>
            <div className="am-settings-right">
              <div className="am-toggle-row">
                <div className="am-toggle-info">
                  <span className="am-toggle-label">Compress Archives</span>
                  <span className="am-toggle-desc">Save storage space</span>
                </div>
                <button
                  className={`am-toggle ${archiveSettings.compressArchives ? 'active' : ''}`}
                  onClick={() => handleToggle('compressArchives')}
                >
                  <span className="am-toggle-knob"></span>
                </button>
              </div>
              <div className="am-toggle-row">
                <div className="am-toggle-info">
                  <span className="am-toggle-label">Backup Archives</span>
                  <span className="am-toggle-desc">Create external backups</span>
                </div>
                <button
                  className={`am-toggle ${archiveSettings.backupArchives ? 'active' : ''}`}
                  onClick={() => handleToggle('backupArchives')}
                >
                  <span className="am-toggle-knob"></span>
                </button>
              </div>
            </div>
          </div>

          <div className="am-settings-footer">
            <button className="am-btn-save" onClick={() => toast.success('Archive settings saved!')}>Save Archive Settings</button>
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default ArchiveManagement;
