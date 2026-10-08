import React, { useState, useEffect, useCallback } from 'react';
import Modal from '../components/Modal';
import { permissionsAPI, usersAPI, navBundlesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ACTION_META, actionsForFeature, pageAccessStatus, featureTitle } from '../utils/permissions';
import { configureFeatures, requiredFeatureOf, ORPHAN_FEATURES, OTHER_HOME, SECTION_ORDER } from '../utils/navConfigureMap';
import '../styles/rbac_console.css';

const ADMIN_ROLE_ID = 1;

// Robustly read the is_granted flag from a role-default row (handles boolean, 1, '1').
const r_is_granted = (row) => row.is_granted === true || row.is_granted === 1 || row.is_granted === '1';

const UserPermissionOverrides = ({ tabSwitcher = null }) => {
  const [users, setUsers]           = useState([]);
  const [selectedUserId, setSelectedUserId] = useState('');
  const [userPerms, setUserPerms]   = useState(null); // {effective:{...}, overrides:[...]}
  const [catalog, setCatalog]       = useState([]);
  const [bundles, setBundles]       = useState([]);
  const [pending, setPending]       = useState({}); // {permission_id: 'grant'|'deny'|'inherit'}
  const [reason, setReason]         = useState('');
  const [loading, setLoading]       = useState(false);
  const [saving, setSaving]         = useState(false);
  const [usersLoading, setUsersLoading] = useState(true);
  const [error, setError]           = useState(null);
  const [allRoleDefaults, setAllRoleDefaults] = useState([]); // [{role_id, permission_id, is_granted}]
  const [modalBundle, setModalBundle] = useState(null);       // { title, blocks:[{feature,title,actions}] } | null
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

  // Row has unsaved changes if any of its configurable features' permission ids are pending.
  const bundleHasPending = (bundle) => {
    const feats = new Set(configureFeatures(bundle));
    return (catalog || []).some((c) => feats.has(c.feature) && c.permission_id in pending);
  };

  // Build the ordered feature sub-blocks (feature title + its actions) for a modal.
  const featureBlocks = (features) =>
    features
      .map((f) => ({ feature: f, title: featureTitle(f), actions: actionsForFeature(catalog, f) }))
      .filter((b) => b.actions.length > 0);

  // Modal open / toggle / apply / cancel handlers.
  // The draft stores the DESIRED effective access per action as a boolean
  // (true = the user should have it). On Apply we compare each to the role
  // default and derive the override: matches default -> inherit; differs ->
  // grant (true) or deny (false). This lets the admin BLOCK an action the
  // role would otherwise allow (e.g. deny view/read), not just add access.
  // Initialise a modal draft from the user's current effective (pending-aware) access.
  const draftFromBlocks = (blocks) => {
    const draft = {};
    blocks.forEach((b) =>
      b.actions.forEach(({ action, permission_id }) => {
        draft[permission_id] = permGrantedNow({ permission_id, feature: b.feature, action });
      })
    );
    return draft;
  };

  const openBundleModal = (bundle) => {
    const blocks = featureBlocks(configureFeatures(bundle));
    setModalDraft(draftFromBlocks(blocks));
    setModalReason('');
    setModalBundle({ title: `${bundle.name} — actions`, blocks });
  };

  const openOtherModal = () => {
    const blocks = featureBlocks(ORPHAN_FEATURES);
    setModalDraft(draftFromBlocks(blocks));
    setModalReason('');
    setModalBundle({ title: `${OTHER_HOME.name} — actions`, blocks });
  };

  // Every action across the open modal's feature blocks, flattened.
  const modalActions = (modalBundle?.blocks || []).flatMap((b) => b.actions);

  const toggleModalAction = (permId) => {
    setModalDraft((d) => ({ ...d, [permId]: !d[permId] }));
  };

  const applyBundleModal = () => {
    const next = {};
    modalActions.forEach(({ permission_id }) => {
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
    modalActions.map(({ permission_id }) => {
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

  // Page-level Save for staged pending changes (from the modal's "Stage" button).
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

  // Group bundles under their sidebar-section headers (SECTION_ORDER; unknown
  // sections trail alphabetically), rows by sort_order.
  const sections = (() => {
    const bySection = {};
    bundles.forEach((b) => {
      const key = b.sidebar_section || '—';
      (bySection[key] = bySection[key] || []).push(b);
    });
    const order = (s) => {
      const i = SECTION_ORDER.indexOf(s);
      return i === -1 ? SECTION_ORDER.length : i;
    };
    return Object.keys(bySection)
      .sort((a, b) => order(a) - order(b) || a.localeCompare(b))
      .map((section) => ({
        section,
        rows: bySection[section].slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)),
      }));
  })();

  // Orphan features that actually exist in the catalog → show the synthetic Other row.
  const otherFeatures = featureBlocks(ORPHAN_FEATURES);

  return (
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

        {tabSwitcher}

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
                {/* Navigation Access list, grouped by sidebar section */}
                {bundles.length === 0 && <p className="rcx-muted">No navigation bundles found.</p>}
                <div className="rcx-navlist">
                  {sections.map(({ section, rows }) => (
                    <React.Fragment key={section}>
                      <h3 className="rcx-navgroup">{section}</h3>
                      {rows.map((bundle) => {
                        const status = bundleStatus(bundle);
                        const dirty = bundleHasPending(bundle);
                        const extraCount = configureFeatures(bundle).length - 1;
                        const labels = { none: 'No access', view: 'View-only', partial: 'Partial', full: 'Full' };
                        return (
                          <div key={bundle.bundle_id} className="rcx-navrow">
                            <div className="rcx-navrow-main">
                              <span className="rcx-navrow-name">{bundle.name}</span>
                              <span className="rcx-navrow-section">
                                {featureTitle(requiredFeatureOf(bundle) || '—')}
                                {extraCount > 0 && ` · +${extraCount} more`}
                              </span>
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
                    </React.Fragment>
                  ))}

                  {otherFeatures.length > 0 && (
                    <React.Fragment>
                      <h3 className="rcx-navgroup">{OTHER_HOME.sidebar_section}</h3>
                      <div className="rcx-navrow">
                        <div className="rcx-navrow-main">
                          <span className="rcx-navrow-name">{OTHER_HOME.name}</span>
                          <span className="rcx-navrow-section">Permissions with no navigation page</span>
                        </div>
                        <div className="rcx-navrow-right">
                          <button type="button" className="rcx-btn rcx-btn--ghost" onClick={openOtherModal}>
                            Configure
                          </button>
                        </div>
                      </div>
                    </React.Fragment>
                  )}
                </div>

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

        {/* Per-page action modal (one sub-block per related feature) */}
        {modalBundle && (
          <Modal isOpen={true} title={modalBundle.title} onClose={cancelBundleModal} maxWidth={480}>
            <div className="rbac-console rbac-console--modal">
            {modalBundle.blocks.length === 0 ? (
              <p className="rcx-muted">This page has no configurable actions.</p>
            ) : modalBundle.blocks.map((block) => (
              <div key={block.feature} style={{ marginBottom: '0.9rem' }}>
                {modalBundle.blocks.length > 1 && <h4 className="rcx-navgroup" style={{ marginTop: 0 }}>{block.title}</h4>}
                <div className="rcx-actionlist">
                  {block.actions.map(({ action, permission_id }) => {
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
              </div>
            ))}
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
            </div>
          </Modal>
        )}
      </div>
  );
};

export default UserPermissionOverrides;
