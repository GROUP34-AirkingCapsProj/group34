import React, { useState, useEffect, useCallback, useRef } from 'react';
import Loader from './Loader';
import Modal from './Modal';
import ProfilePhoto from './ProfilePhoto';
import PasswordFieldWithToggle from './PasswordFieldWithToggle';
import ArchiveButton from './ArchiveButton';
import { usersAPI, authAPI, batchAPI } from '../services/api';
import { avatarColor } from './Sidebar';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import { getPageBatch } from '../utils/pageBatch';
import '../styles/dashboard_air.css';
import '../styles/user_management.css';
import '../styles/archive_management.css';

/**
 * The server's password rule, restated so the form can reject a weak entry
 * before the round trip: at least 8 characters with upper and lower case, a
 * digit, and a symbol.
 */
const PASSWORD_POLICY = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/;
const PASSWORD_POLICY_HINT =
  'Use at least 8 characters with upper and lower case, a number, and a symbol.';

const UserManagementPanel = () => {
  const [users, setUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [locations, setLocations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [previewUser, setPreviewUser] = useState(null);
  const [photoZoom, setPhotoZoom] = useState(1);
  const [failedPhotos, setFailedPhotos] = useState(new Set());
  const [searchTerm, setSearchTerm] = useState('');
  const [filterRole, setFilterRole] = useState('');
  const [filterLocation, setFilterLocation] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [formErrors, setFormErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [deactivatingId, setDeactivatingId] = useState(null);
  const [resendingId, setResendingId] = useState(null);
  const [currentUserId, setCurrentUserId] = useState(null);
  const rowsPerPage = 8;
  const debounceRef = useRef(null);

  const [formData, setFormData] = useState({
    first_name: '', last_name: '', username: '', email: '', recovery_email: '',
    password: '', password_confirmation: '', current_password: '',
    phone: '', role_id: '', location_id: '', status_id: 1
  });

  // Load reference data (roles, locations) once on mount
  useEffect(() => {
    const loadReferenceData = async () => {
      try {
        const res = await batchAPI.get({ include: ['roles', 'locations'] });
        const data = res?.data ?? {};
        setRoles(Array.isArray(data.roles?.data) ? data.roles.data : []);
        setLocations(Array.isArray(data.locations?.data) ? data.locations.data : []);
      } catch (err) {
        console.error('Failed to fetch reference data:', err);
      }
    };
    loadReferenceData();
  }, []);

  // Fetch paginated users from server
  const fetchUsers = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm) params.search = searchTerm;
      if (filterRole) params.role_id = filterRole;
      if (filterLocation) params.location_id = filterLocation;
      if (filterStatus) {
        params.status_id = filterStatus === 'Active' ? 1 : 2;
      }
      const res = await usersAPI.getAll(params);
      setUsers(Array.isArray(res.data) ? res.data : []);
      setPagination(res.pagination || { total: 0, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load data');
      console.error('Failed to fetch users:', err);
      setUsers([]);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, filterRole, filterLocation, filterStatus, rowsPerPage]);

  // Fetch users when page changes
  useEffect(() => {
    fetchUsers(currentPage);
  }, [currentPage, fetchUsers]);

  // Debounce search/filter changes — reset to page 1
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setCurrentPage(1);
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, filterRole, filterLocation, filterStatus]);

  useEffect(() => {
    authAPI.getUser().then((res) => {
      const uid = res?.data?.user_id ?? res?.data?.id ?? res?.user?.user_id ?? res?.user?.id;
      if (uid != null) setCurrentUserId(uid);
    }).catch(() => {});
  }, []);

  const getUserName = (user) => {
    if (user.first_name && user.last_name) return `${user.first_name} ${user.last_name}`;
    return user.name || user.username || 'N/A';
  };

  const getUserRole = (user) => user.role?.description || user.role?.role_name || 'N/A';
  const getUserLocation = (user) => user.location?.location_name || user.location?.name || user.location_name || 'N/A';
  const getUserStatus = (user) => {
    if (user.status?.status_name) return user.status.status_name;
    return user.status_id === 1 ? 'Active' : 'Inactive';
  };
  const getUserId = (user) => user.user_id || user.id || 'N/A';
  const getUserInitials = (user) => {
    if (user.first_name && user.last_name) return (user.first_name.charAt(0) + user.last_name.charAt(0)).toUpperCase();
    if (user.username) return user.username.charAt(0).toUpperCase();
    return 'U';
  };

  // Stats from pagination metadata
  const totalUsers = pagination.total || 0;

  const handleAdd = () => {
    setEditingUser(null);
    setFormData({
      first_name: '', last_name: '', username: '', email: '', recovery_email: '',
      password: '', password_confirmation: '', current_password: '',
      phone: '', role_id: '', location_id: '', status_id: 1
    });
    setFormErrors({});
    setSubmitError('');
    setIsModalOpen(true);
  };

  const handleEdit = (user) => {
    setEditingUser(user);
    setFormData({
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      username: user.username || '',
      email: user.email || '',
      recovery_email: user.recovery_email || '',
      password: '',
      password_confirmation: '',
      current_password: '',
      phone: user.phone || '',
      role_id: user.role_id || '',
      location_id: user.location_id || '',
      status_id: user.status_id || 1
    });
    setFormErrors({});
    setSubmitError('');
    setIsModalOpen(true);
  };

  const handleArchiveUser = async (user) => {
    const id = user.user_id || user.id;
    try {
      await usersAPI.delete(id);
      // Archiving is a soft delete: the row keeps its username, login email and
      // recovery email, and keeps the unique index on all three. Saying so here
      // is what stops the next step being a confusing "already in use" error on
      // a recreate.
      toast.success(
        `User ${getUserName(user)} archived. Their username and email addresses stay reserved `
        + 'until the account is restored, or released in Archive Management.'
      );
      await fetchUsers(currentPage);
    } catch (err) { toast.error(err.message || 'Failed to archive user.'); }
  };

  const handleDeactivate = async (user) => {
    const id = user.user_id || user.id;
    if (currentUserId != null && Number(id) === Number(currentUserId)) {
      toast.warning('You cannot deactivate your own account.');
      return;
    }
    const status = getUserStatus(user);
    const action = status === 'Active' ? 'deactivate' : 'activate';
    const newStatusId = status === 'Active' ? 2 : 1;

    if (!(await confirmAction({ title: `Are you sure you want to ${action} ${getUserName(user)}?`, confirmText: 'Confirm', tone: 'danger' }))) return;

    setDeactivatingId(id);
    try {
      await usersAPI.updateStatus(id, newStatusId);
      toast.success(`User ${action}d successfully.`);
      await fetchUsers(currentPage);
    } catch (err) {
      toast.error(err.message || `Failed to ${action} user.`);
    } finally {
      setDeactivatingId(null);
    }
  };

  const handleResendCredentials = async (user) => {
    const id = user.user_id || user.id;
    const ok = await confirmAction({
      title: 'Resend login details?',
      text: `A new temporary password will be generated and emailed to ${user.recovery_email || 'their recovery address'}. Their current temporary password will stop working.`,
      confirmText: 'Resend',
      tone: 'primary',
    });
    if (!ok) return;

    setResendingId(id);
    try {
      const res = await usersAPI.resendCredentials(id);
      if (res?.data?.credentials_emailed === false) {
        toast.error('The email could not be sent. Check the mail configuration and try again.');
      } else {
        toast.success('New login details sent.');
      }
    } catch (err) {
      toast.error(err.message || 'Failed to resend login details.');
    } finally {
      setResendingId(null);
    }
  };

  // Changing your OWN password through this form costs the current one, the
  // same as every other password entry point in the app.
  const editingSelf = !!editingUser
    && currentUserId != null
    && Number(editingUser.user_id || editingUser.id) === Number(currentUserId);

  const validateForm = () => {
    const errors = {};

    if (!formData.first_name.trim()) errors.first_name = 'First name is required';
    if (!formData.last_name.trim()) errors.last_name = 'Last name is required';
    if (!formData.username.trim()) errors.username = 'Username is required';
    if (!formData.email.trim()) errors.email = 'Email is required';
    else if (!/\S+@\S+\.\S+/.test(formData.email)) errors.email = 'Invalid email format';

    if (!editingUser) {
      if (!formData.recovery_email.trim()) errors.recovery_email = 'Recovery email is required';
      else if (!/\S+@\S+\.\S+/.test(formData.recovery_email)) errors.recovery_email = 'Invalid email format';
    }
    if (
      formData.recovery_email.trim()
      && formData.recovery_email.trim().toLowerCase() === formData.email.trim().toLowerCase()
    ) {
      errors.recovery_email = 'Recovery email must be different from the login email';
    }

    // Create mode has no password field at all -- the backend generates one and
    // emails it. Edit mode keeps the optional admin-set password unchanged.
    if (editingUser && formData.password) {
      // Mirrors the server rule (Password::min(8)->mixedCase()->numbers()->symbols()).
      // Checking only the length here meant the admin filled the form, waited for
      // the request, and got a 422 back for a rule nothing on screen had stated.
      if (!PASSWORD_POLICY.test(formData.password)) errors.password = PASSWORD_POLICY_HINT;
      if (formData.password !== formData.password_confirmation) errors.password_confirmation = 'Passwords do not match';
      if (editingSelf && !formData.current_password) {
        errors.current_password = 'Enter your current password to change your own password';
      }
    }

    if (!formData.role_id) errors.role_id = 'Position/Role is required';

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateForm()) return;

    setSubmitting(true);
    setSubmitError('');

    try {
      if (editingUser) {
        const payload = { ...formData };
        if (!payload.password) {
          delete payload.password;
          delete payload.password_confirmation;
        }
        if (!payload.password || !editingSelf) delete payload.current_password;
        payload.recovery_email = String(payload.recovery_email || '').trim();
        if (!payload.recovery_email) delete payload.recovery_email;
        payload.role_id = Number(payload.role_id) || null;
        payload.location_id = Number(payload.location_id) || null;
        payload.status_id = Number(payload.status_id) || 1;
        const id = editingUser.user_id || editingUser.id;
        await usersAPI.update(id, payload);
        toast.success('User updated successfully!');
        setIsModalOpen(false);
        await fetchUsers(currentPage);
      } else {
        const roleId = Number(formData.role_id);
        const locationId = formData.location_id ? Number(formData.location_id) : null;
        if (!roleId) {
          setSubmitting(false);
          return;
        }
        const payload = {
          first_name: String(formData.first_name || '').trim(),
          last_name: String(formData.last_name || '').trim(),
          username: String(formData.username || '').trim(),
          email: String(formData.email || '').trim(),
          recovery_email: String(formData.recovery_email || '').trim(),
          phone: (formData.phone && String(formData.phone).trim()) || null,
          role_id: roleId,
          location_id: locationId,
          status_id: 1,
        };
        const res = await usersAPI.create(payload);
        if (res?.data?.credentials_emailed === false) {
          // The account exists and has a working password -- it just did not
          // reach the user. Resend is the recovery, so name it.
          toast.error('User created, but the credentials email failed to send. Use Resend on their row to try again.');
        } else {
          toast.success(`User created. Login details emailed to ${payload.recovery_email}.`);
        }
        setIsModalOpen(false);
        await fetchUsers(currentPage);
      }
    } catch (err) {
      setSubmitError(err.message || 'Request failed.');
      if (err.errors && typeof err.errors === 'object') {
        const normalized = {};
        Object.keys(err.errors).forEach((k) => {
          const v = err.errors[k];
          normalized[k] = Array.isArray(v) ? v[0] : v;
        });
        setFormErrors(normalized);
      } else {
        setFormErrors({});
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleResetFilters = () => {
    setSearchTerm('');
    setFilterRole('');
    setFilterLocation('');
    setFilterStatus('');
  };

  // Server-side pagination
  const totalPages = pagination.last_page || 1;

  return (
    <>
      <div className="um-panel-toolbar">
        <button className="ak-btn ak-btn--primary" onClick={handleAdd}>+ Add New User</button>
      </div>

      {loading && <Loader label="Loading users" />}
      {error && <div className="error-message">Error: {error}</div>}

      {/* Stats Cards */}
      <div className="um-stats-grid">
        <div className="um-stat-card">
          <div className="um-stat-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </div>
          <div className="um-stat-info">
            <span className="um-stat-value">{totalUsers}</span>
            <span className="um-stat-label">Total Users</span>
          </div>
        </div>
        <div className="um-stat-card">
          <div className="um-stat-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          </div>
          <div className="um-stat-info">
            <span className="um-stat-value">{users.filter(u => getUserStatus(u) === 'Active').length}</span>
            <span className="um-stat-label">Active (this page)</span>
          </div>
        </div>
        <div className="um-stat-card">
          <div className="um-stat-icon red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
          </div>
          <div className="um-stat-info">
            <span className="um-stat-value">{users.filter(u => getUserStatus(u) === 'Inactive').length}</span>
            <span className="um-stat-label">Inactive (this page)</span>
          </div>
        </div>
        <div className="um-stat-card">
          <div className="um-stat-icon orange">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
          </div>
          <div className="um-stat-info">
            <span className="um-stat-value">{[...new Set(users.filter(u => u.location_id).map(u => u.location_id))].length}</span>
            <span className="um-stat-label">Locations (this page)</span>
          </div>
        </div>
      </div>

      {/* Search & Filter Section */}
      <div className="um-filter-section">
        <div className="um-search-box">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input
            type="text"
            placeholder="Search by name, email, username, or ID..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="um-filters">
          <select value={filterRole} onChange={(e) => setFilterRole(e.target.value)}>
            <option value="">All Positions</option>
            {roles.map(role => (
              <option key={role.role_id} value={role.role_id}>{role.description || role.role_name}</option>
            ))}
          </select>
          <select value={filterLocation} onChange={(e) => setFilterLocation(e.target.value)}>
            <option value="">All Locations</option>
            {locations.map(loc => (
              <option key={loc.location_id ?? loc.id} value={loc.location_id ?? loc.id}>{loc.location_name || loc.name}</option>
            ))}
          </select>
          <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
            <option value="">All Status</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
          <button className="um-btn-reset" onClick={handleResetFilters}>Reset</button>
        </div>
      </div>

      {/* User Table */}
      <div className="um-card">
        <div className="um-card-header">
          <div>
            <h3>All Users</h3>
            <span>{pagination.total || 0} users total</span>
          </div>
        </div>

        <div className="um-table-wrapper">
          <table className="um-table">
            <thead>
              <tr>
                <th>EMPLOYEE ID</th>
                <th>NAME</th>
                <th>EMAIL</th>
                <th>POSITION</th>
                <th>LOCATION</th>
                <th>STATUS</th>
                <th>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {users.length === 0 ? (
                <tr><td colSpan="7" style={{ textAlign: 'center', color: 'var(--ink-slate-400)', padding: '40px' }}>{loading ? <Loader /> : 'No users found'}</td></tr>
              ) : (
                users.map((user) => (
                  <tr key={user.user_id || user.id}>
                    <td className="um-id">{getUserId(user)}</td>
                    <td>
                      <div className="um-user-cell">
                        <button type="button" className="um-avatar um-avatar-button"
                          style={{ background: avatarColor(user.first_name || user.username || 'U') }}
                          disabled={!user.avatar_url || failedPhotos.has(user.avatar_url)}
                          aria-label={`View profile photo of ${getUserName(user)}`}
                          title={user.avatar_url && !failedPhotos.has(user.avatar_url) ? 'Click to enlarge photo' : 'No profile photo available'}
                          onClick={() => { setPreviewUser(user); setPhotoZoom(1); }}>
                          <ProfilePhoto src={user.avatar_url} alt={`${getUserName(user)} profile photo`}
                            fallback={getUserInitials(user)} onError={() => setFailedPhotos((previous) => new Set([...previous, user.avatar_url]))} />
                        </button>
                        <div className="um-user-info">
                          <span className="um-user-name">{getUserName(user)}</span>
                          <span className="um-user-username">@{user.username || 'N/A'}</span>
                        </div>
                      </div>
                    </td>
                    <td className="um-email">{user.email || 'N/A'}</td>
                    <td>
                      <span className="um-role-badge">{getUserRole(user)}</span>
                    </td>
                    <td>{getUserLocation(user)}</td>
                    <td>
                      <span className={`um-status ${getUserStatus(user) === 'Active' ? 'active' : 'inactive'}`}>
                        {getUserStatus(user)}
                      </span>
                    </td>
                    <td>
                      <div className="um-actions">
                        <button className="um-btn-edit" onClick={() => handleEdit(user)} disabled={deactivatingId !== null}>Edit</button>
                        <button
                          className="um-btn-resend"
                          onClick={() => handleResendCredentials(user)}
                          disabled={resendingId !== null || deactivatingId !== null || !user.must_change_password || !user.recovery_email}
                          title={!user.must_change_password
                            ? 'This user has already set their own password — they should use Forgot password'
                            : !user.recovery_email
                              ? 'This account has no recovery email on file — add one via Edit first'
                              : 'Email new login details to this user'}
                        >
                          {resendingId === (user.user_id || user.id) ? 'Sending...' : 'Resend'}
                        </button>
                        <button
                          className={getUserStatus(user) === 'Active' ? 'um-btn-deactivate' : 'um-btn-activate'}
                          onClick={() => handleDeactivate(user)}
                          disabled={deactivatingId !== null || (currentUserId != null && Number(currentUserId) === Number(user.user_id || user.id))}
                          title={currentUserId != null && Number(currentUserId) === Number(user.user_id || user.id) ? 'You cannot deactivate your own account' : (getUserStatus(user) === 'Active' ? 'Deactivate this user' : 'Activate this user')}
                        >
                          {deactivatingId === (user.user_id || user.id)
                            ? (getUserStatus(user) === 'Active' ? 'Deactivating...' : 'Activating...')
                            : (getUserStatus(user) === 'Active' ? 'Deactivate' : 'Activate')}
                        </button>
                        <ArchiveButton
                          onArchive={() => handleArchiveUser(user)}
                          disabled={currentUserId != null && Number(currentUserId) === Number(user.user_id || user.id)}
                        />
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {totalPages > 1 && (() => {
          const batch = getPageBatch(currentPage, totalPages);
          return (
            <div className="um-pagination">
              <button className="um-jump" disabled={!batch.hasPrevBatch} onClick={() => setCurrentPage(batch.prevBatchPage)} aria-label="Previous batch" title="Previous batch">«</button>
              <button disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
              {batch.pages.map(page => (
                <button key={page} className={currentPage === page ? 'active' : ''} onClick={() => setCurrentPage(page)}>{page}</button>
              ))}
              <button disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
              <button className="um-jump" disabled={!batch.hasNextBatch} onClick={() => setCurrentPage(batch.nextBatchPage)} aria-label="Next batch" title="Next batch">»</button>
            </div>
          );
        })()}
      </div>

      {/* Add/Edit User Modal */}
      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title={editingUser ? 'Edit User' : 'Add New User'}>
        <form onSubmit={handleSubmit} className="um-form">
          {submitError && (
            <div className="um-field-error" style={{ marginBottom: '12px', padding: '8px 12px', background: 'var(--danger-soft-bg)', borderRadius: '4px' }}>
              {submitError}
            </div>
          )}
          {!editingUser && roles.length === 0 && (
            <div style={{ marginBottom: '12px', padding: '8px 12px', background: 'var(--tint-amber)', borderRadius: '4px', fontSize: '14px' }}>
              No roles loaded. Refresh the page or try again.
              <button type="button" onClick={() => { setSubmitError(''); fetchUsers(currentPage); }} style={{ marginLeft: '8px', padding: '4px 8px' }}>Retry load</button>
            </div>
          )}
          {!editingUser && (
            <p style={{ marginBottom: '16px', fontSize: '13px', color: 'var(--text-muted)' }}>
              Enter the new user's personal information, account credentials, then choose their role and branch.
            </p>
          )}
          {/* Personal Information Section */}
          <div className="um-form-section">
            <h4 className="um-form-section-title">Personal Information</h4>
            <div className="form-row">
              <div className="form-group">
                <label>First Name <span className="required">*</span></label>
                <input type="text" value={formData.first_name} onChange={(e) => setFormData({...formData, first_name: e.target.value})} placeholder="Enter first name" />
                {formErrors.first_name && <span className="um-field-error">{formErrors.first_name}</span>}
              </div>
              <div className="form-group">
                <label>Last Name <span className="required">*</span></label>
                <input type="text" value={formData.last_name} onChange={(e) => setFormData({...formData, last_name: e.target.value})} placeholder="Enter last name" />
                {formErrors.last_name && <span className="um-field-error">{formErrors.last_name}</span>}
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Email Address <span className="required">*</span></label>
                <input type="email" value={formData.email} onChange={(e) => setFormData({...formData, email: e.target.value})} placeholder="user@airking.com" />
                <small className="um-field-hint">Used to sign in.</small>
                {formErrors.email && <span className="um-field-error">{formErrors.email}</span>}
              </div>
              <div className="form-group">
                {/* Required when creating; optional on edit, so legacy users
                    without one can be saved without forcing a value. Mirrors the
                    backend's required/sometimes split. */}
                <label>Recovery Email {!editingUser && <span className="required">*</span>}</label>
                <input type="email" value={formData.recovery_email} onChange={(e) => setFormData({...formData, recovery_email: e.target.value})} placeholder="personal@gmail.com" />
                <small className="um-field-hint">Personal inbox. Password reset codes are sent here.</small>
                {formErrors.recovery_email && <span className="um-field-error">{formErrors.recovery_email}</span>}
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Phone Number</label>
                <input type="text" value={formData.phone} onChange={(e) => setFormData({...formData, phone: e.target.value})} placeholder="e.g. 09171234567" />
              </div>
            </div>
          </div>

          {/* Account Credentials Section */}
          <div className="um-form-section">
            <h4 className="um-form-section-title">Account Credentials</h4>
            <div className="form-row">
              <div className="form-group">
                <label>Username <span className="required">*</span></label>
                <input type="text" value={formData.username} onChange={(e) => setFormData({...formData, username: e.target.value})} placeholder="Enter username" />
                {formErrors.username && <span className="um-field-error">{formErrors.username}</span>}
              </div>
              {editingUser ? (
                <div className="form-group">
                  <label htmlFor="um-modal-password">New Password (leave blank to keep current)</label>
                  <PasswordFieldWithToggle
                    id="um-modal-password"
                    name="password"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder="Leave blank to keep current"
                    autoComplete="new-password"
                  />
                  {formErrors.password && <span className="um-field-error">{formErrors.password}</span>}
                </div>
              ) : (
                <div className="form-group">
                  {/* A span, not a label: there is no control to associate it
                      with in create mode, and an orphan <label> is announced as
                      one by screen readers. */}
                  <span className="um-pseudo-label">Password</span>
                  <p className="um-credentials-note">
                    A temporary password is generated automatically and emailed to
                    the recovery address above. The user must change it the first
                    time they sign in.
                  </p>
                </div>
              )}
            </div>
            {editingUser && formData.password && (
              <div className="form-row">
                <div className="form-group">
                  <label htmlFor="um-modal-password-confirm">Confirm Password</label>
                  <PasswordFieldWithToggle
                    id="um-modal-password-confirm"
                    name="password_confirmation"
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    placeholder="Re-enter password"
                    autoComplete="new-password"
                  />
                  {formErrors.password_confirmation && <span className="um-field-error">{formErrors.password_confirmation}</span>}
                </div>
                {editingSelf ? (
                  <div className="form-group">
                    <label htmlFor="um-modal-current-password">Your Current Password <span className="required">*</span></label>
                    <PasswordFieldWithToggle
                      id="um-modal-current-password"
                      name="current_password"
                      value={formData.current_password}
                      onChange={(e) => setFormData({ ...formData, current_password: e.target.value })}
                      placeholder="Enter your current password"
                      autoComplete="current-password"
                    />
                    {formErrors.current_password && <span className="um-field-error">{formErrors.current_password}</span>}
                  </div>
                ) : (
                  <div className="form-group"></div>
                )}
              </div>
            )}
          </div>

          {/* Location & Position Assignment Section */}
          <div className="um-form-section">
            <h4 className="um-form-section-title">Location & Position Assignment</h4>
            <div className="form-row">
              <div className="form-group">
                <label>Position / Role <span className="required">*</span></label>
                <select value={formData.role_id} onChange={(e) => setFormData({...formData, role_id: e.target.value})}>
                  <option value="">Select Position</option>
                  {roles.map(role => (
                    <option key={role.role_id} value={role.role_id}>{role.description || role.role_name}</option>
                  ))}
                </select>
                {formErrors.role_id && <span className="um-field-error">{formErrors.role_id}</span>}
              </div>
              <div className="form-group">
                <label>Assigned Location</label>
                <select value={formData.location_id} onChange={(e) => setFormData({...formData, location_id: e.target.value})}>
                  <option value="">Select Location</option>
                  {locations.map(loc => (
                    <option key={loc.location_id ?? loc.id} value={loc.location_id ?? loc.id}>
                      {loc.location_name || loc.name}{loc.location_code ? ` (${loc.location_code})` : ''}
                    </option>
                  ))}
                </select>
                {formErrors.location_id && <span className="um-field-error">{formErrors.location_id}</span>}
              </div>
            </div>
            {editingUser && (
              <div className="form-row">
                <div className="form-group">
                  <label>Status</label>
                  <select value={formData.status_id} onChange={(e) => setFormData({...formData, status_id: parseInt(e.target.value)})}>
                    <option value={1}>Active</option>
                    <option value={2}>Inactive</option>
                  </select>
                </div>
                <div className="form-group"></div>
              </div>
            )}
          </div>

          <div className="modal-footer">
            <button type="button" className="btn-cancel" onClick={() => setIsModalOpen(false)} disabled={submitting}>Cancel</button>
            <button type="submit" className="btn-save" disabled={submitting}>
              {submitting ? (editingUser ? 'Updating...' : 'Adding...') : (editingUser ? 'Update User' : 'Add User')}
            </button>
          </div>
        </form>
      </Modal>
      <Modal isOpen={Boolean(previewUser)} onClose={() => setPreviewUser(null)}
        title={previewUser ? `${getUserName(previewUser)} — Profile photo` : 'Profile photo'} maxWidth={820}>
        <div className="um-photo-controls">
          <button type="button" className="ak-btn ak-btn--ghost" aria-label="Zoom out" disabled={photoZoom <= 1}
            onClick={() => setPhotoZoom((zoom) => Math.max(1, zoom - 0.5))}>−</button>
          <span aria-live="polite">{Math.round(photoZoom * 100)}%</span>
          <button type="button" className="ak-btn ak-btn--ghost" aria-label="Zoom in" disabled={photoZoom >= 3}
            onClick={() => setPhotoZoom((zoom) => Math.min(3, zoom + 0.5))}>+</button>
          <button type="button" className="ak-btn ak-btn--ghost" onClick={() => setPhotoZoom(1)}>Reset zoom</button>
        </div>
        <div className="um-photo-preview">
          {previewUser && <ProfilePhoto src={previewUser.avatar_url} alt={`${getUserName(previewUser)} full profile photo`}
            style={{ width: `${photoZoom * 100}%`, height: `${photoZoom * 60}vh`, objectFit: 'contain', maxWidth: 'none', display: 'block' }}
            fallback={<p role="alert">This profile photo is unavailable. Please upload it again in Account Settings.</p>} />}
        </div>
      </Modal>
    </>
  );
};

export default UserManagementPanel;
