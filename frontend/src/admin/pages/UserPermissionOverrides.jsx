import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { permissionsAPI, usersAPI, navBundlesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ACTIONS, ACTION_META, groupByFeatureGroup, actionsForFeature, pageAccessStatus } from '../utils/permissions';
import '../styles/rbac_console.css';

const ADMIN_ROLE_ID = 1;

// Robustly read the is_granted flag from a role-default row (handles boolean, 1, '1').
const r_is_granted = (row) => row.is_granted === true || row.is_granted === 1 || row.is_granted === '1';

// Tri-state: 'grant' | 'deny' | 'inherit'
const TriStateSelect = ({ value, onChange, baselineGranted, isPending }) => (
  <select
    className={`rcx-tri${isPending ? ' is-pending' : ''}`}
    value={value}
    onChange={(e) => onChange(e.target.value)}
    style={{
      color: value === 'grant' ? 'var(--rc-green)' : value === 'deny' ? 'var(--rc-crimson)' : 'var(--rc-ink-soft)',
    }}
    title={isPending
      ? 'Unsaved change — save to apply'
      : `Role default: ${baselineGranted ? 'Granted' : 'Denied'}`}
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
  const [allRoleDefaults, setAllRoleDefaults] = useState([]); // [{role_id, permission_id, is_granted}]
  const [modalBundle, setModalBundle] = useState(null);       // { bundle, feature, actions } | null
  const [modalDraft, setModalDraft]   = useState({});         // { permission_id: boolean desired }
  const [modalReason, setModalReason] = useState('');         // reason used when saving from the modal

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

  // Load catalog + nav bundles + role defaults once
  useEffect(() => {
    const loadCatalogAndBundles = async () => {
      try {
        const [catRes, bundleRes, roleDefRes] = await Promise.all([
          permissionsAPI.catalog(),
          navBundlesAPI.list(),
          permissionsAPI.getRoleDefaults(),
        ]);
        setCatalog(Array.isArray(catRes?.data)   ? catRes.data   : []);
        setBundles(Array.isArray(bundleRes?.data) ? bundleRes.data : []);
        setAllRoleDefaults(Array.isArray(roleDefRes?.data) ? roleDefRes.data : []);
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

  // --- Simple tab: nav bundle helpers ---
  // Resolve a single permission's state with the pending overlay on top of the
  // saved effective state, so the UI reflects unsaved clicks immediately.
  const permGrantedNow = (p) => {
    if (p.permission_id in pending) {
      const st = pending[p.permission_id];
      if (st === 'grant') return true;
      if (st === 'deny') return false;
      // 'inherit' falls through to the saved effective value
    }
    return !!userPerms?.effective?.[`${p.feature}.${p.action}`];
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

  // Determine if selected user is admin (block override attempt)
  const selectedUser = users.find((u) => String(u.user_id) === String(selectedUserId));
  const isAdminUser  = selectedUser?.role?.role_id === ADMIN_ROLE_ID ||
                       selectedUser?.role_id === ADMIN_ROLE_ID;

  const selRoleId = selectedUser?.role?.role_id ?? selectedUser?.role_id ?? null;

  // True if the SELECTED user's role grants this permission by default (pure role baseline).
  const roleDefaultGranted = (permId) => {
    if (selRoleId == null) return false;
    const row = allRoleDefaults.find(
      (r) => Number(r.role_id) === Number(selRoleId) && Number(r.permission_id) === Number(permId)
    );
    return !!(row && r_is_granted(row));
  };

  // The primary feature behind a nav bundle (its required permission's feature).
  const bundleFeature = (bundle) => {
    const perms = bundle.permissions || [];
    const req = perms.find((p) => p.is_required) || perms[0];
    return req?.feature || null;
  };

  // Effective (pending-aware) status badge for a bundle's primary feature.
  const bundleStatus = (bundle) => {
    const feature = bundleFeature(bundle);
    const acts = actionsForFeature(catalog, feature);
    const eff = {};
    acts.forEach(({ action, permission_id }) => {
      eff[action] = permGrantedNow({ permission_id, feature, action });
    });
    return pageAccessStatus(acts, eff);
  };

  // Row has unsaved changes if any of its feature's permission ids are pending.
  const bundleHasPending = (bundle) => {
    const feature = bundleFeature(bundle);
    return (catalog || []).some((c) => c.feature === feature && c.permission_id in pending);
  };

  // Modal open / toggle / apply / cancel handlers.
  // The draft stores the DESIRED effective access per action as a boolean
  // (true = the user should have it). On Apply we compare each to the role
  // default and derive the override: matches default -> inherit; differs ->
  // grant (true) or deny (false). This lets the admin BLOCK an action the
  // role would otherwise allow (e.g. deny view/read), not just add access.
  const openBundleModal = (bundle) => {
    const feature = bundleFeature(bundle);
    const acts = actionsForFeature(catalog, feature);
    const draft = {};
    acts.forEach(({ action, permission_id }) => {
      // Initialise from the user's current effective (pending-aware) access.
      draft[permission_id] = permGrantedNow({ permission_id, feature, action });
    });
    setModalDraft(draft);
    setModalReason('');
    setModalBundle({ bundle, feature, actions: acts });
  };

  const toggleModalAction = (permId) => {
    setModalDraft((d) => ({ ...d, [permId]: !d[permId] }));
  };

  const applyBundleModal = () => {
    const next = {};
    (modalBundle?.actions || []).forEach(({ permission_id }) => {
      const desired = !!modalDraft[permission_id];
      const roleDef = roleDefaultGranted(permission_id);
      // matches role default -> no override; otherwise explicit grant/deny
      next[permission_id] = desired === roleDef ? 'inherit' : (desired ? 'grant' : 'deny');
    });
    setPending((prev) => ({ ...prev, ...next }));
    setModalBundle(null);
    setModalDraft({});
  };

  const cancelBundleModal = () => {
    setModalBundle(null);
    setModalDraft({});
    setModalReason('');
  };

  // Derive the {permission_id, is_granted} change list for the open modal's draft.
  const draftToChanges = () =>
    (modalBundle?.actions || []).map(({ permission_id }) => {
      const desired = !!modalDraft[permission_id];
      const roleDef = roleDefaultGranted(permission_id);
      const state = desired === roleDef ? 'inherit' : (desired ? 'grant' : 'deny');
      return { permission_id: Number(permission_id), is_granted: state === 'inherit' ? null : state === 'grant' };
    });

  // Shared commit: validates reason + changes, persists, reloads. Returns true on success.
  const commitOverrides = async (reasonText, changes) => {
    if (!selectedUserId) return false;
    if (!reasonText.trim()) {
      toast.error('A reason is required before saving overrides.');
      return false;
    }
    if (!changes || changes.length === 0) {
      toast.info('No changes to save.');
      return false;
    }
    setSaving(true);
    try {
      await permissionsAPI.updateUserPermissions(selectedUserId, reasonText.trim(), changes);
      toast.success('User permission overrides saved.');
      await loadUserPerms(selectedUserId);
      return true;
    } catch (err) {
      toast.error(err.message || 'Failed to save overrides.');
      return false;
    } finally {
      setSaving(false);
    }
  };

  // Page-level Save (Advanced tab / staged pending).
  const handleSave = async () => {
    const changes = Object.entries(pending).map(([permId, state]) => ({
      permission_id: Number(permId),
      is_granted: state === 'inherit' ? null : state === 'grant',
    }));
    const ok = await commitOverrides(reason, changes);
    if (ok) setReason('');
  };

  // Save directly from the per-page modal, using the modal's own reason.
  const saveBundleModal = async () => {
    const ok = await commitOverrides(modalReason, draftToChanges());
    if (ok) { setModalBundle(null); setModalDraft({}); setModalReason(''); }
  };

  const pendingCount = Object.keys(pending).length;
  const groups       = groupByFeatureGroup(catalog);

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

                {/* Simple tab: nav bundle row list */}
                {tab === 'simple' && (
                  <div>
                    {bundles.length === 0 && <p className="rcx-muted">No navigation bundles found.</p>}
                    <div className="rcx-navlist">
                      {bundles.map((bundle) => {
                        const status = bundleStatus(bundle);
                        const dirty = bundleHasPending(bundle);
                        const labels = { none: 'No access', view: 'View-only', partial: 'Partial', full: 'Full' };
                        return (
                          <div key={bundle.bundle_id} className="rcx-navrow">
                            <div className="rcx-navrow-main">
                              <span className="rcx-navrow-name">{bundle.name}</span>
                              <span className="rcx-navrow-section">{bundle.sidebar_section || '—'}</span>
                            </div>
                            <div className="rcx-navrow-right">
                              {dirty && <span className="rcx-navrow-dirty" title="Unsaved changes" />}
                              <span className={`rcx-statusb rcx-statusb--${status}`}>{labels[status]}</span>
                              <button type="button" className="rcx-btn rcx-btn--ghost" onClick={() => openBundleModal(bundle)}>
                                Configure
                              </button>
                            </div>
                          </div>
                        );
                      })}
                    </div>
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
                                        isPending={permId in pending}
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

        {/* Per-page action modal */}
        {modalBundle && (
          <Modal isOpen={true} title={`${modalBundle.bundle.name} — actions`} onClose={cancelBundleModal} maxWidth={460}>
            <div className="rcx-actionlist">
              {modalBundle.actions.map(({ action, permission_id }) => {
                const viaRole = roleDefaultGranted(permission_id);
                const checked = !!modalDraft[permission_id];
                // Will this checkbox produce an explicit override (differs from role default)?
                const willOverride = checked !== viaRole;
                return (
                  <label className="rcx-actionrow" key={permission_id}>
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleModalAction(permission_id)}
                    />
                    <span className="rcx-actionrow-label">{ACTION_META[action]?.label || action}</span>
                    <span className={`rcx-actionrow-hint ${viaRole ? 'rcx-actionrow-hint--role' : ''}`}>
                      role: {viaRole ? 'allowed' : 'blocked'}
                    </span>
                    {willOverride && (
                      <span className={`rcx-actionrow-hint ${checked ? 'rcx-actionrow-hint--role' : 'rcx-actionrow-hint--deny'}`}>
                        {checked ? 'grant' : 'deny'}
                      </span>
                    )}
                  </label>
                );
              })}
            </div>
            <p className="rcx-modal-note">
              Tick to <strong>allow</strong> an action for this user, untick to <strong>block</strong> it.
              Leaving it the same as the role default keeps it inherited; changing it sets a per-user
              grant or deny — including denying an action the role normally allows (e.g. view access).
            </p>
            <div className="rcx-modal-footer">
              <label className="rcx-label" style={{ display: 'block', marginBottom: 6 }}>
                Reason <span className="rcx-reason-req">*</span>
              </label>
              <input
                className="rcx-input"
                type="text"
                value={modalReason}
                onChange={(e) => setModalReason(e.target.value)}
                placeholder="Why are you changing this user's access?"
                style={{ width: '100%', boxSizing: 'border-box', marginBottom: '0.75rem' }}
              />
              <div className="rcx-modal-actions">
                <button type="button" className="rcx-btn rcx-btn--ghost" onClick={cancelBundleModal} disabled={saving}>Cancel</button>
                <button type="button" className="rcx-btn rcx-btn--ghost" onClick={applyBundleModal} disabled={saving} title="Stage these changes — save later with the page Save button">Stage</button>
                <button type="button" className="rcx-btn rcx-btn--primary" onClick={saveBundleModal} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
              </div>
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default UserPermissionOverrides;
