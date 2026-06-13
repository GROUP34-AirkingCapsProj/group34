import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI, usersAPI, navBundlesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ACTIONS, ACTION_META, groupByFeatureGroup } from '../utils/permissions';
import '../styles/dashboard_air.css';

const ADMIN_ROLE_ID = 1;

// Tri-state: 'grant' | 'deny' | 'inherit'
const TRISTATE_LABELS = {
  grant:   { label: 'Grant',   color: '#059669' },
  deny:    { label: 'Deny',    color: '#dc2626' },
  inherit: { label: 'Inherit', color: 'var(--text-muted)' },
};

const TriStateSelect = ({ value, onChange, baselineGranted }) => (
  <select
    value={value}
    onChange={(e) => onChange(e.target.value)}
    style={{
      fontSize: '0.78rem',
      padding: '2px 4px',
      borderRadius: 4,
      border: '1px solid var(--border-color, #e2e8f0)',
      color: TRISTATE_LABELS[value]?.color || 'inherit',
      background: 'var(--bg-card, #fff)',
      cursor: 'pointer',
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
    setError(null);
    if (id) loadUserPerms(id);
  };

  // Effective value from API (true/false) — the baseline for displaying "Inherit"
  const effectiveKey = (feature, action) => `${feature}.${action}`;
  const effectiveGranted = (permId) => {
    if (!userPerms?.effective) return false;
    // Find feature.action key for this permId
    const p = catalog.find((c) => c.permission_id === Number(permId));
    if (!p) return false;
    return !!userPerms.effective[`${p.feature}.${p.action}`];
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
      <div className="cb-page">
        <div className="cb-page-header">
          <div>
            <h1>User Permission Overrides</h1>
            <p>Grant or deny individual permissions for a specific user, overriding their role defaults.</p>
          </div>
        </div>

        {/* User picker */}
        <div className="cb-section" style={{ marginBottom: '1.5rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', flexWrap: 'wrap' }}>
            <label style={{ fontWeight: 600, minWidth: 80 }}>Select user:</label>
            {usersLoading ? (
              <span style={{ color: 'var(--text-muted)' }}>Loading users…</span>
            ) : (
              <select
                value={selectedUserId}
                onChange={handleUserSelect}
                style={{
                  padding: '0.4rem 0.75rem',
                  borderRadius: 6,
                  border: '1px solid var(--border-color, #e2e8f0)',
                  minWidth: 240,
                  background: 'var(--bg-card, #fff)',
                }}
              >
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
            <div className="cb-error" style={{ marginTop: '0.75rem' }}>
              Admin users cannot have permission overrides.
            </div>
          )}
        </div>

        {selectedUserId && !isAdminUser && (
          <>
            {loading && <div className="cb-loading">Loading permissions…</div>}
            {error   && <div className="cb-error">Error: {error}</div>}

            {!loading && !error && userPerms && (
              <>
                {/* Tabs */}
                <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1.25rem', borderBottom: '2px solid var(--border-color, #e2e8f0)' }}>
                  {['simple', 'advanced'].map((t) => (
                    <button
                      key={t}
                      type="button"
                      onClick={() => setTab(t)}
                      style={{
                        padding: '0.5rem 1.25rem',
                        border: 'none',
                        background: 'none',
                        cursor: 'pointer',
                        fontWeight: tab === t ? 700 : 400,
                        borderBottom: tab === t ? '2px solid #2563eb' : '2px solid transparent',
                        marginBottom: -2,
                        color: tab === t ? '#2563eb' : 'var(--text-muted)',
                        fontSize: '0.92rem',
                        textTransform: 'capitalize',
                      }}
                    >
                      {t === 'simple' ? 'Navigation Access' : 'Advanced (Full Matrix)'}
                    </button>
                  ))}
                </div>

                {/* Simple tab: nav bundles */}
                {tab === 'simple' && (
                  <div>
                    {bundles.length === 0 && (
                      <p style={{ color: 'var(--text-muted)' }}>No navigation bundles found.</p>
                    )}
                    <table className="cb-table">
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
                              <td style={{ fontWeight: 500 }}>{bundle.name}</td>
                              <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                                {bundle.sidebar_section || '—'}
                              </td>
                              <td style={{ fontFamily: 'monospace', fontSize: '0.82rem', color: 'var(--text-muted)' }}>
                                {bundle.route_path || '—'}
                              </td>
                              <td style={{ textAlign: 'center' }}>
                                <button
                                  type="button"
                                  onClick={() => toggleBundle(bundle, !on)}
                                  style={{
                                    padding: '0.25rem 0.75rem',
                                    borderRadius: 4,
                                    border: 'none',
                                    cursor: 'pointer',
                                    fontWeight: 600,
                                    fontSize: '0.8rem',
                                    background: on ? '#dcfce7' : '#fee2e2',
                                    color: on ? '#15803d' : '#b91c1c',
                                  }}
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
                  <div style={{ overflowX: 'auto' }}>
                    <table className="cb-table" style={{ minWidth: 480 }}>
                      <thead>
                        <tr>
                          <th style={{ minWidth: 220 }}>Feature / Action</th>
                          <th style={{ textAlign: 'center', minWidth: 160 }}>Override</th>
                        </tr>
                      </thead>
                      <tbody>
                        {groups.map((group) => (
                          <React.Fragment key={group.label}>
                            <tr>
                              <td
                                colSpan={2}
                                style={{
                                  background: 'var(--bg-surface, #f8fafc)',
                                  fontWeight: 700,
                                  fontSize: '0.78rem',
                                  letterSpacing: '0.04em',
                                  color: 'var(--text-muted)',
                                  padding: '0.55rem 0.75rem',
                                  textTransform: 'uppercase',
                                }}
                              >
                                {group.label}
                              </td>
                            </tr>
                            {group.features.map((feat) =>
                              ACTIONS.filter((a) => feat.actions[a] != null).map((action) => {
                                const permId = feat.actions[action];
                                const state  = stateFor(permId);
                                const base   = roleDefault(permId);
                                return (
                                  <tr key={`${feat.feature}.${action}`}>
                                    <td>
                                      <span style={{ fontWeight: 500 }}>{feat.title}</span>
                                      <span
                                        style={{
                                          marginLeft: '0.5rem',
                                          fontSize: '0.75rem',
                                          color: 'var(--text-muted)',
                                          background: 'var(--bg-surface, #f1f5f9)',
                                          borderRadius: 4,
                                          padding: '1px 6px',
                                        }}
                                      >
                                        {ACTION_META[action]?.label || action}
                                      </span>
                                    </td>
                                    <td style={{ textAlign: 'center' }}>
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
                <div
                  style={{
                    marginTop: '1.5rem',
                    padding: '1rem',
                    background: 'var(--bg-surface, #f8fafc)',
                    borderRadius: 8,
                    border: '1px solid var(--border-color, #e2e8f0)',
                  }}
                >
                  <div style={{ marginBottom: '0.75rem' }}>
                    <label style={{ fontWeight: 600, display: 'block', marginBottom: 4 }}>
                      Reason for changes <span style={{ color: '#dc2626' }}>*</span>
                    </label>
                    <input
                      type="text"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder="Describe why you are changing these permissions…"
                      style={{
                        width: '100%',
                        padding: '0.45rem 0.75rem',
                        borderRadius: 6,
                        border: '1px solid var(--border-color, #e2e8f0)',
                        background: 'var(--bg-card, #fff)',
                        fontSize: '0.9rem',
                        boxSizing: 'border-box',
                      }}
                    />
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
                    <button
                      type="button"
                      className="cb-btn-primary"
                      onClick={handleSave}
                      disabled={saving || pendingCount === 0}
                      style={{ minWidth: 130 }}
                    >
                      {saving ? 'Saving…' : `Save${pendingCount > 0 ? ` (${pendingCount})` : ''}`}
                    </button>
                    {pendingCount > 0 && (
                      <button
                        type="button"
                        className="cb-btn-secondary"
                        onClick={() => setPending({})}
                        disabled={saving}
                      >
                        Discard
                      </button>
                    )}
                    {pendingCount === 0 && (
                      <span style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>
                        No pending changes.
                      </span>
                    )}
                  </div>
                </div>
              </>
            )}
          </>
        )}

        {!selectedUserId && !usersLoading && (
          <div
            style={{
              textAlign: 'center',
              padding: '3rem',
              color: 'var(--text-muted)',
              background: 'var(--bg-surface, #f8fafc)',
              borderRadius: 8,
              border: '1px dashed var(--border-color, #e2e8f0)',
            }}
          >
            Select a user above to view and edit their permission overrides.
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default UserPermissionOverrides;
