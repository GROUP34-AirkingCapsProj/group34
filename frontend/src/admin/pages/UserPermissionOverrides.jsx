import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI, usersAPI, navBundlesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ACTIONS, ACTION_META, groupByFeatureGroup } from '../utils/permissions';
import '../styles/rbac_console.css';

const ADMIN_ROLE_ID = 1;

// Tri-state: 'grant' | 'deny' | 'inherit'
const TriStateSelect = ({ value, onChange, baselineGranted }) => (
  <select
    className="rcx-tri"
    value={value}
    onChange={(e) => onChange(e.target.value)}
    style={{
      color: value === 'grant' ? 'var(--rc-green)' : value === 'deny' ? 'var(--rc-crimson)' : 'var(--rc-ink-soft)',
    }}
    title={`Role default: ${baselineGranted ? 'Granted' : 'Denied'}`}
  >
    <option value="inherit">Inherit ({baselineGranted ? 'Granted' : 'Denied'})</option>
    <option value="grant">Grant</option>
    <option value="deny">Deny</option>
  </select>
);

const UserPermissionOverrides = () => {
  const [users, setUsers]           = useState([]);
  const [selectedUserId, setSelectedUserId] = useState('');
  const [userPerms, setUserPerms]   = useState(null); // {effective:{...}, overrides:[...]}
  const [catalog, setCatalog]       = useState([]);
  const [bundles, setBundles]       = useState([]);
  const [tab, setTab]               = useState('simple'); // 'simple' | 'advanced'
  const [pending, setPending]       = useState({}); // {permission_id: 'grant'|'deny'|'inherit'}
  const [reason, setReason]         = useState('');
  const [loading, setLoading]       = useState(false);
  const [saving, setSaving]         = useState(false);
  const [usersLoading, setUsersLoading] = useState(true);
  const [error, setError]           = useState(null);

  // Load users once
  useEffect(() => {
    const loadUsers = async () => {
      try {
        setUsersLoading(true);
        const res = await usersAPI.getAll({ per_page: 200 });
        const list = Array.isArray(res?.data) ? res.data : [];
        setUsers(list);
      } catch (err) {
        toast.error('Failed to load users: ' + (err.message || 'Unknown error'));
      } finally {
        setUsersLoading(false);
      }
    };
    loadUsers();
  }, []);

  // Load catalog + nav bundles once
  useEffect(() => {
    const loadCatalogAndBundles = async () => {
      try {
        const [catRes, bundleRes] = await Promise.all([
          permissionsAPI.catalog(),
          navBundlesAPI.list(),
        ]);
        setCatalog(Array.isArray(catRes?.data)   ? catRes.data   : []);
        setBundles(Array.isArray(bundleRes?.data) ? bundleRes.data : []);
      } catch (err) {
        console.error('Failed to load catalog/bundles:', err);
      }
    };
    loadCatalogAndBundles();
  }, []);

  const loadUserPerms = useCallback(async (userId) => {
    if (!userId) return;
    try {
      setLoading(true);
      setError(null);
      const res = await permissionsAPI.getUserPermissions(userId);
      setUserPerms(res?.data || { effective: {}, overrides: [] });
      setPending({});
    } catch (err) {
      setError(err.message || 'Failed to load user permissions.');
      setUserPerms(null);
    } finally {
      setLoading(false);
    }
  }, []);

  const handleUserSelect = (e) => {
    const id = e.target.value;
    setSelectedUserId(id);
    setUserPerms(null);
    setPending({});
    setReason(''); // don't carry a previous user's reason into a new override save
    setError(null);
    if (id) loadUserPerms(id);
  };

  // Current explicit override from API
  const currentOverride = (permId) => {
    if (!userPerms?.overrides) return null;
    const ov = userPerms.overrides.find((o) => o.permission_id === Number(permId));
    if (!ov) return null;
    return ov.is_granted === true ? 'grant' : 'deny';
  };

  // Pending or API state for a permId
  const stateFor = (permId) => {
    if (permId in pending) return pending[permId];
    return currentOverride(permId) || 'inherit';
  };

  const setPermState = (permId, value) => {
    setPending((prev) => ({ ...prev, [permId]: value }));
  };

  // --- Simple tab: nav bundle toggles ---
  // A bundle is "on" if the user has ALL required perms from effective
  const bundleEffective = (bundle) => {
    if (!userPerms?.effective) return false;
    const required = (bundle.permissions || []).filter((p) => p.is_required);
    if (required.length === 0) return true;
    return required.every((p) => {
      const key = `${p.feature}.${p.action}`;
      return !!userPerms.effective[key];
    });
  };

  const toggleBundle = (bundle, forceOn) => {
    const perms = bundle.permissions || [];
    if (perms.length === 0) return;
    const updates = {};
    perms.forEach((p) => {
      updates[p.permission_id] = forceOn ? 'grant' : 'deny';
    });
    setPending((prev) => ({ ...prev, ...updates }));
  };

  // Role default for a permId (what "Inherit" would resolve to)
  // We derive this from effective when there's no override
  const roleDefault = (permId) => {
    if (!userPerms) return false;
    const p = catalog.find((c) => c.permission_id === Number(permId));
    if (!p) return false;
    // If no override, effective IS the role default
    const ovr = userPerms.overrides?.find((o) => o.permission_id === Number(permId));
    if (ovr != null) {
      // effective is the override result; role default = effective XOR override
      // We don't have role default directly, so approximate from effective
      return !!userPerms.effective[`${p.feature}.${p.action}`];
    }
    return !!userPerms.effective[`${p.feature}.${p.action}`];
  };

  const handleSave = async () => {
    if (!selectedUserId) return;
    if (!reason.trim()) {
      toast.error('A reason is required before saving overrides.');
      return;
    }
    const changes = Object.entries(pending).map(([permId, state]) => ({
      permission_id: Number(permId),
      is_granted: state === 'inherit' ? null : state === 'grant',
    }));
    if (changes.length === 0) {
      toast.info('No changes to save.');
      return;
    }
    setSaving(true);
    try {
      await permissionsAPI.updateUserPermissions(selectedUserId, reason.trim(), changes);
      toast.success('User permission overrides saved.');
      setReason('');
      await loadUserPerms(selectedUserId);
    } catch (err) {
      toast.error(err.message || 'Failed to save overrides.');
    } finally {
      setSaving(false);
    }
  };

  const pendingCount = Object.keys(pending).length;
  const groups       = groupByFeatureGroup(catalog);

  // Determine if selected user is admin (block override attempt)
  const selectedUser = users.find((u) => String(u.user_id) === String(selectedUserId));
  const isAdminUser  = selectedUser?.role?.role_id === ADMIN_ROLE_ID ||
                       selectedUser?.role_id === ADMIN_ROLE_ID;

  return (
    <AdminLayout>
      <div className="rbac-console">
        <header className="rcx-header">
          <div className="rcx-header-row">
            <div>
              <p className="rcx-eyebrow">Access Control · User Overrides</p>
              <h1 className="rcx-title">User Permission Overrides</h1>
              <p className="rcx-sub">
                Grant or deny individual permissions for a specific user, overriding their role
                defaults. Every change is logged with a reason.
              </p>
            </div>
          </div>
        </header>

        {/* User picker */}
        <div className="rcx-panel" style={{ marginBottom: '1.25rem' }}>
          <div className="rcx-picker">
            <span className="rcx-label">Select user</span>
            {usersLoading ? (
              <span className="rcx-muted">Loading users…</span>
            ) : (
              <select className="rcx-select" value={selectedUserId} onChange={handleUserSelect} style={{ minWidth: 260 }}>
                <option value="">— Choose a user —</option>
                {users
                  .filter((u) => {
                    const rid = u.role?.role_id ?? u.role_id;
                    return rid !== ADMIN_ROLE_ID;
                  })
                  .map((u) => (
                    <option key={u.user_id} value={u.user_id}>
                      {u.first_name && u.last_name
                        ? `${u.first_name} ${u.last_name}`
                        : u.username || u.email}{' '}
                      ({u.role?.role_name || 'Unknown role'})
                    </option>
                  ))}
              </select>
            )}
          </div>
          {isAdminUser && (
            <div className="rcx-state rcx-state--error" style={{ marginTop: '0.85rem', padding: '0.75rem 1rem', textAlign: 'left' }}>
              Admin users cannot have permission overrides.
            </div>
          )}
        </div>

        {selectedUserId && !isAdminUser && (
          <>
            {loading && <div className="rcx-state">Loading permissions<span className="rcx-loading-dots" /></div>}
            {error   && <div className="rcx-state rcx-state--error">Error: {error}</div>}

            {!loading && !error && userPerms && (
              <div className="rcx-panel">
                {/* Tabs */}
                <div className="rcx-tabs">
                  {['simple', 'advanced'].map((t) => (
                    <button
                      key={t}
                      type="button"
                      className={`rcx-tab${tab === t ? ' is-active' : ''}`}
                      onClick={() => setTab(t)}
                    >
                      {t === 'simple' ? 'Navigation Access' : 'Advanced · Full Matrix'}
                    </button>
                  ))}
                </div>

                {/* Simple tab: nav bundles */}
                {tab === 'simple' && (
                  <div>
                    {bundles.length === 0 && (
                      <p className="rcx-muted">No navigation bundles found.</p>
                    )}
                    <table className="rcx-table">
                      <thead>
                        <tr>
                          <th>Navigation Bundle</th>
                          <th>Section</th>
                          <th>Path</th>
                          <th style={{ textAlign: 'center' }}>Access</th>
                        </tr>
                      </thead>
                      <tbody>
                        {bundles.map((bundle) => {
                          const on = bundleEffective(bundle);
                          return (
                            <tr key={bundle.bundle_id}>
                              <td style={{ fontWeight: 600 }}>{bundle.name}</td>
                              <td className="rcx-mono rcx-muted" style={{ fontSize: '0.74rem' }}>{bundle.sidebar_section || '—'}</td>
                              <td className="rcx-mono rcx-muted">{bundle.route_path || '—'}</td>
                              <td style={{ textAlign: 'center' }}>
                                <button
                                  type="button"
                                  className={`rcx-access ${on ? 'is-on' : 'is-off'}`}
                                  onClick={() => toggleBundle(bundle, !on)}
                                >
                                  {on ? 'Granted' : 'Denied'}
                                </button>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}

                {/* Advanced tab: full matrix */}
                {tab === 'advanced' && (
                  <div className="rcx-matrix-wrap" style={{ boxShadow: 'none', border: '1px solid var(--rc-line)' }}>
                    <table className="rcx-matrix" style={{ minWidth: 480 }}>
                      <thead>
                        <tr>
                          <th>Feature / Action</th>
                          <th className="rcx-rolehead" style={{ minWidth: 180 }}>Override</th>
                        </tr>
                      </thead>
                      <tbody>
                        {groups.map((group) => (
                          <React.Fragment key={group.label}>
                            <tr className="rcx-group">
                              <td colSpan={2}>{group.label}</td>
                            </tr>
                            {group.features.map((feat) =>
                              ACTIONS.filter((a) => feat.actions[a] != null).map((action) => {
                                const permId = feat.actions[action];
                                const state  = stateFor(permId);
                                const base   = roleDefault(permId);
                                return (
                                  <tr className="rcx-row" key={`${feat.feature}.${action}`}>
                                    <td className="rcx-feat">
                                      <span className="rcx-feat-name">{feat.title}</span>
                                      <span className="rcx-action-tag">{ACTION_META[action]?.label || action}</span>
                                    </td>
                                    <td className="rcx-cell">
                                      <TriStateSelect
                                        value={state}
                                        onChange={(v) => setPermState(permId, v)}
                                        baselineGranted={base}
                                      />
                                    </td>
                                  </tr>
                                );
                              })
                            )}
                          </React.Fragment>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {/* Reason + Save */}
                <div className="rcx-reason">
                  <div style={{ marginBottom: '0.75rem' }}>
                    <label className="rcx-label" style={{ display: 'block', marginBottom: 6 }}>
                      Reason for changes <span className="rcx-reason-req">*</span>
                    </label>
                    <input
                      className="rcx-input"
                      type="text"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder="Describe why you are changing these permissions…"
                      style={{ width: '100%', boxSizing: 'border-box' }}
                    />
                  </div>
                  <div className="rcx-reason-row">
                    <button
                      type="button"
                      className="rcx-btn rcx-btn--primary"
                      onClick={handleSave}
                      disabled={saving || pendingCount === 0}
                    >
                      {saving ? 'Saving…' : `Save${pendingCount > 0 ? ` · ${pendingCount}` : ''}`}
                    </button>
                    {pendingCount > 0 && (
                      <button
                        type="button"
                        className="rcx-btn rcx-btn--ghost"
                        onClick={() => setPending({})}
                        disabled={saving}
                      >
                        Discard
                      </button>
                    )}
                    {pendingCount === 0 && (
                      <span className="rcx-muted" style={{ fontSize: '0.82rem' }}>No pending changes.</span>
                    )}
                  </div>
                </div>
              </div>
            )}
          </>
        )}

        {!selectedUserId && !usersLoading && (
          <div className="rcx-state">
            Select a user above to view and edit their permission overrides.
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default UserPermissionOverrides;
