import React, { useEffect, useMemo, useState, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI, batchAPI, navBundlesAPI } from '../services/api';
import { groupByFeatureGroup, ACTIONS, ACTION_META } from '../utils/permissions';
import { toast } from '../utils/toast';
import PermissionGuide from '../components/PermissionGuide';
import '../styles/permission_admin.css';

/* ─── Navigation Access helpers ─────────────────────────────────────── */

/**
 * Derive a bundle's toggle state from the user's effective permissions.
 * Returns 'on' | 'partial' | 'off'.
 */
function bundleState(bundle, effective) {
  const perms = bundle.permissions ?? [];
  if (perms.length === 0) return 'off';
  // Per spec §4: derive state from required (read-gate) perms only.
  // Fall back to all perms only if none are marked is_required.
  const target = perms.filter((p) => p.is_required);
  const scope = target.length > 0 ? target : perms;
  const grantedCount = scope.filter((p) => effective[`${p.feature}.${p.action}`] === true).length;
  if (grantedCount === 0) return 'off';
  if (grantedCount === scope.length) return 'on';
  return 'partial';
}

/** Simple icon renderer — uses a Unicode fallback mapped to known icon slugs. */
const ICON_MAP = {
  items: '📦',
  inventory: '🗄️',
  users: '👤',
  roles: '🔒',
  dashboard: '📊',
  reports: '📋',
  sales: '🛒',
  purchase: '🏪',
  transfer: '🔄',
  receiving: '📥',
  issuance: '📤',
  adjustment: '⚖️',
  audit: '🔍',
  supplier: '🏭',
  category: '🏷️',
  brand: '🔖',
  warranty: '🛡️',
  budget: '💰',
  settings: '⚙️',
};
const BundleIcon = ({ icon }) => (
  <span style={{ fontSize: 22, marginRight: 8 }}>
    {ICON_MAP[icon] ?? '📄'}
  </span>
);

/* ─── NavigationAccessPanel ──────────────────────────────────────────── */
const NavigationAccessPanel = ({ userId, effective, onPermissionsChanged }) => {
  const [bundles, setBundles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(null); // bundle_id being saved

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    navBundlesAPI.list()
      .then((res) => { if (!cancelled) setBundles(res?.data ?? []); })
      .catch((e) => toast.error(e.message || 'Failed to load navigation bundles'))
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  const grouped = useMemo(() => {
    const m = {};
    bundles.forEach((b) => {
      const section = b.sidebar_section || 'General';
      if (!m[section]) m[section] = [];
      m[section].push(b);
    });
    // Sort each section by sort_order
    Object.values(m).forEach((arr) => arr.sort((a, b) => (a.sort_order ?? 99) - (b.sort_order ?? 99)));
    return m;
  }, [bundles]);

  const handleToggle = useCallback(async (bundle) => {
    const state = bundleState(bundle, effective);
    const isGranted = state !== 'on'; // ON → turn off; anything else → turn on
    const changes = bundle.permissions.map((p) => ({
      permission_id: p.permission_id,
      is_granted: isGranted,
    }));
    setSaving(bundle.bundle_id);
    try {
      await permissionsAPI.updateUserPermissions(
        userId,
        `Navigation Access: ${bundle.name}`,
        changes,
      );
      toast.success(`${bundle.name} ${isGranted ? 'enabled' : 'disabled'}`);
      onPermissionsChanged();
    } catch (e) {
      toast.error(e.message || 'Failed to update permissions');
    } finally {
      setSaving(null);
    }
  }, [userId, effective, onPermissionsChanged]);

  if (loading) {
    return <p className="perm-muted">Loading navigation bundles…</p>;
  }

  if (bundles.length === 0) {
    return <p className="perm-muted">No navigation bundles configured.</p>;
  }

  return (
    <div className="nav-bundles-panel">
      {Object.entries(grouped).map(([section, sectionBundles]) => (
        <div key={section} className="nav-bundle-section">
          <h3 className="nav-bundle-section-title">{section}</h3>
          <div className="nav-bundle-cards">
            {sectionBundles.map((bundle) => {
              const state = bundleState(bundle, effective);
              const isSaving = saving === bundle.bundle_id;
              return (
                <div
                  key={bundle.bundle_id}
                  className={`nav-bundle-card nav-bundle-card--${state}`}
                >
                  <div className="nav-bundle-card-header">
                    <BundleIcon icon={bundle.sidebar_icon} />
                    <span className="nav-bundle-card-name">{bundle.name}</span>
                    {state === 'partial' && (
                      <span className="nav-bundle-badge nav-bundle-badge--partial">Partial</span>
                    )}
                  </div>
                  {bundle.description && (
                    <p className="nav-bundle-card-desc">{bundle.description}</p>
                  )}
                  <div className="nav-bundle-card-footer">
                    <button
                      type="button"
                      className={`nav-bundle-toggle ${state === 'on' ? 'nav-bundle-toggle--on' : 'nav-bundle-toggle--off'}`}
                      onClick={() => handleToggle(bundle)}
                      disabled={isSaving}
                      aria-pressed={state === 'on'}
                      title={`${state === 'on' ? 'Disable' : 'Enable'} ${bundle.name}`}
                    >
                      {isSaving ? 'Saving…' : state === 'on' ? 'ON' : 'OFF'}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
};

/* ─── Main component ─────────────────────────────────────────────────── */

const UserPermissionOverrides = ({ initialUserId } = {}) => {
  const [users, setUsers] = useState([]);
  const [catalog, setCatalog] = useState([]);
  const [userId, setUserId] = useState(initialUserId ? String(initialUserId) : '');
  const [effective, setEffective] = useState({}); // 'feature.action' => bool
  const [overrides, setOverrides] = useState({}); // permId => bool (active override)
  const [pending, setPending] = useState({});     // permId => bool|null (null = clear)
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [activeFeatureTab, setActiveFeatureTab] = useState(0); // tab index within Advanced grid

  // Top-level view tabs: 'nav' or 'advanced'
  const [viewTab, setViewTab] = useState('nav');

  useEffect(() => {
    (async () => {
      const [cat, batch] = await Promise.all([
        permissionsAPI.catalog(),
        batchAPI.get({ include: ['users'] }),
      ]);
      setCatalog(cat?.data ?? []);
      setUsers(batch?.data?.users?.data ?? []);
    })().catch((e) => toast.error(e.message));
  }, []);

  const loadUser = useCallback(async (id) => {
    setUserId(id);
    setPending({});
    setReason('');
    if (!id) { setEffective({}); setOverrides({}); return; }
    try {
      const res = await permissionsAPI.getUserPermissions(id);
      setEffective(res?.data?.effective ?? {});
      const ov = {};
      (res?.data?.overrides ?? []).forEach((o) => { ov[o.permission_id] = !!o.is_granted; });
      setOverrides(ov);
    } catch (e) { toast.error(e.message); }
  }, []);

  // Load initial user if prop provided
  useEffect(() => {
    if (initialUserId) {
      loadUser(String(initialUserId));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialUserId]);

  const groups = useMemo(() => groupByFeatureGroup(catalog), [catalog]);
  const permFeatureAction = useMemo(() => {
    const m = {};
    catalog.forEach((p) => { m[p.permission_id] = `${p.feature}.${p.action}`; });
    return m;
  }, [catalog]);

  const isOverridden = (permId) => (permId in pending ? pending[permId] !== null : permId in overrides);
  const effectiveValue = (permId) => {
    if (permId in pending) {
      if (pending[permId] !== null) return pending[permId];
    } else if (permId in overrides) {
      return overrides[permId];
    }
    return !!effective[permFeatureAction[permId]];
  };

  // Cycle: default → grant → deny → default
  const cycle = (permId) => {
    setPending((p) => {
      const copy = { ...p };
      const current = permId in copy ? copy[permId] : (permId in overrides ? overrides[permId] : 'default');
      let next;
      if (current === 'default' || current === null) next = true;
      else if (current === true) next = false;
      else next = null; // clear back to default
      copy[permId] = next;
      return copy;
    });
  };

  const save = async () => {
    const changes = Object.entries(pending).map(([permission_id, v]) => ({
      permission_id: Number(permission_id),
      is_granted: v, // true | false | null(clear)
    }));
    if (changes.length === 0) { toast.info('No changes'); return; }
    if (!reason.trim()) { toast.error('A reason is required'); return; }
    setSaving(true);
    try {
      await permissionsAPI.updateUserPermissions(userId, reason.trim(), changes);
      toast.success('Overrides saved');
      await loadUser(userId);
    } catch (e) {
      toast.error(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const pendingPermIds = useMemo(() => new Set(Object.keys(pending).map(Number)), [pending]);
  const groupHasPending = (g) => g.features.some((f) => Object.values(f.actions).some((pid) => pendingPermIds.has(pid)));
  const activeIndex = groups.length ? Math.min(activeFeatureTab, groups.length - 1) : 0;
  const active = groups[activeIndex];

  return (
    <AdminLayout>
      <div className="perm-admin">
        <div className="perm-header">
          <h1>User Permission Overrides</h1>
          <p>Grant or deny specific permissions for one user. An override always wins over that user&rsquo;s role default.</p>
        </div>

        <PermissionGuide showOverrideKey />

        <div className="perm-toolbar">
          <select value={userId} onChange={(e) => loadUser(e.target.value)}>
            <option value="">Select a user…</option>
            {users.map((u) => (
              <option key={u.user_id} value={u.user_id}>
                {u.first_name} {u.last_name} ({u.role?.role_name})
              </option>
            ))}
          </select>
          <input placeholder="Reason (required to save)" value={reason}
                 onChange={(e) => setReason(e.target.value)} style={{ flex: 1, minWidth: 200 }} />
          <button className="perm-btn-primary" onClick={save} disabled={saving || !userId}>
            {saving ? 'Saving…' : 'Save overrides'}
          </button>
        </div>

        {!userId && <p className="perm-muted">Select a user above to view and edit their overrides.</p>}

        {userId && (
          <>
            {/* ── View-level tab switcher: Navigation Access | Advanced ── */}
            <div
              className="perm-view-tabs"
              role="tablist"
              aria-label="Permission editor view"
              style={{ display: 'flex', gap: 4, marginBottom: 16, borderBottom: '2px solid #e5e7eb' }}
            >
              <button
                type="button"
                role="tab"
                aria-selected={viewTab === 'nav'}
                className={`perm-view-tab${viewTab === 'nav' ? ' perm-view-tab--active' : ''}`}
                onClick={() => setViewTab('nav')}
                style={{
                  padding: '8px 18px',
                  fontWeight: viewTab === 'nav' ? 700 : 400,
                  background: 'none',
                  borderTop: 'none',
                  borderLeft: 'none',
                  borderRight: 'none',
                  borderBottom: viewTab === 'nav' ? '2px solid #2563eb' : '2px solid transparent',
                  cursor: 'pointer',
                  color: viewTab === 'nav' ? '#2563eb' : '#6b7280',
                  marginBottom: -2,
                }}
              >
                Navigation Access
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={viewTab === 'advanced'}
                className={`perm-view-tab${viewTab === 'advanced' ? ' perm-view-tab--active' : ''}`}
                onClick={() => setViewTab('advanced')}
                style={{
                  padding: '8px 18px',
                  fontWeight: viewTab === 'advanced' ? 700 : 400,
                  background: 'none',
                  borderTop: 'none',
                  borderLeft: 'none',
                  borderRight: 'none',
                  borderBottom: viewTab === 'advanced' ? '2px solid #2563eb' : '2px solid transparent',
                  cursor: 'pointer',
                  color: viewTab === 'advanced' ? '#2563eb' : '#6b7280',
                  marginBottom: -2,
                }}
              >
                Advanced
                {pendingPermIds.size > 0 && (
                  <span className="perm-tab-dot" title="Unsaved changes" style={{ marginLeft: 6 }} />
                )}
              </button>
            </div>

            {/* ── Navigation Access panel ── */}
            {viewTab === 'nav' && (
              <NavigationAccessPanel
                userId={userId}
                effective={effective}
                onPermissionsChanged={() => loadUser(userId)}
              />
            )}

            {/* ── Advanced: existing 91-permission grid ── */}
            {viewTab === 'advanced' && (
              <>
                <div className="perm-tabs" role="tablist">
                  {groups.map((g, i) => (
                    <button
                      type="button"
                      key={g.label}
                      className={`perm-tab ${i === activeIndex ? 'active' : ''}`}
                      onClick={() => setActiveFeatureTab(i)}
                    >
                      {g.label}
                      {groupHasPending(g) && <span className="perm-tab-dot" title="Unsaved changes in this category" />}
                    </button>
                  ))}
                </div>

                {active && (
                  <div className="perm-card" key={active.label}>
                    <h2>{active.label}</h2>
                    <div style={{ overflowX: 'auto' }}>
                      <table className="perm-table">
                        <thead>
                          <tr>
                            <th className="perm-feature-cell">Feature</th>
                            {ACTIONS.map((a) => <th key={a} title={ACTION_META[a].desc}>{ACTION_META[a].label}</th>)}
                          </tr>
                        </thead>
                        <tbody>
                          {active.features.map((f) => (
                            <tr key={f.feature}>
                              <td className="perm-feature-cell">{f.title}</td>
                              {ACTIONS.map((a) => {
                                const permId = f.actions[a];
                                if (!permId) return <td key={a} className="perm-cell-na">&mdash;</td>;
                                const val = effectiveValue(permId);
                                const overridden = isOverridden(permId);
                                const cls = overridden ? (val ? 'perm-override-on' : 'perm-override-off') : (val ? 'perm-allow' : 'perm-deny');
                                return (
                                  <td key={a} className={`perm-cell-toggle ${cls}`}
                                      title={`${ACTION_META[a].label}: ${val ? 'allowed' : 'not allowed'}${overridden ? ' (override)' : ' (role default)'} — click to change`}
                                      onClick={() => cycle(permId)}>
                                    {val ? '✓' : '✗'}{overridden ? '∗' : ''}
                                  </td>
                                );
                              })}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
              </>
            )}
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default UserPermissionOverrides;
