import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ROLE_COLUMNS, ACTIONS, ACTION_META, groupByFeatureGroup } from '../utils/permissions';
import '../styles/rbac_console.css';

const ADMIN_ROLE_ID = 1;

const CheckGlyph = () => (
  <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <polyline points="20 6 9 17 4 12" />
  </svg>
);

const RolePermissions = () => {
  const [catalog, setCatalog]   = useState([]);
  const [granted, setGranted]   = useState({}); // granted[role_id][permission_id] = bool
  const [pending, setPending]   = useState({}); // pending[role_id][permission_id] = bool (diff only)
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const [catRes, roleRes] = await Promise.all([
        permissionsAPI.catalog(),
        permissionsAPI.getRoleDefaults(),
      ]);
      const cat  = Array.isArray(catRes?.data)  ? catRes.data  : [];
      const rows = Array.isArray(roleRes?.data) ? roleRes.data : [];

      // Build grant lookup: granted[role_id][permission_id] = bool
      const g = {};
      rows.forEach(({ role_id, permission_id, is_granted }) => {
        if (!g[role_id]) g[role_id] = {};
        g[role_id][permission_id] = Boolean(is_granted);
      });
      setCatalog(cat);
      setGranted(g);
      setPending({});
    } catch (err) {
      setError(err.message || 'Failed to load permissions.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const isGranted = (roleId, permId) => {
    if (pending[roleId] && permId in pending[roleId]) return pending[roleId][permId];
    return !!(granted[roleId] && granted[roleId][permId]);
  };

  const toggle = (roleId, permId) => {
    if (roleId === ADMIN_ROLE_ID) return; // admin is read-only
    const current = isGranted(roleId, permId);
    const original = !!(granted[roleId] && granted[roleId][permId]);
    const next = !current;

    setPending((prev) => {
      const roleMap = { ...(prev[roleId] || {}) };
      if (next === original) {
        // revert to original — remove from pending
        delete roleMap[permId];
      } else {
        roleMap[permId] = next;
      }
      const newPending = { ...prev, [roleId]: roleMap };
      if (Object.keys(newPending[roleId]).length === 0) delete newPending[roleId];
      return newPending;
    });
  };

  const hasPendingChanges = Object.values(pending).some((m) => Object.keys(m).length > 0);

  const handleSave = async () => {
    const changes = [];
    Object.entries(pending).forEach(([roleId, perms]) => {
      Object.entries(perms).forEach(([permId, isGrantedVal]) => {
        changes.push({
          role_id: Number(roleId),
          permission_id: Number(permId),
          is_granted: isGrantedVal,
        });
      });
    });
    if (changes.length === 0) return;

    setSaving(true);
    try {
      await permissionsAPI.updateRoleDefaults(changes);
      toast.success('Role permissions saved successfully.');
      await load();
    } catch (err) {
      toast.error(err.message || 'Failed to save permissions.');
    } finally {
      setSaving(false);
    }
  };

  const groups = groupByFeatureGroup(catalog);

  const pendingCount = Object.values(pending).reduce(
    (acc, m) => acc + Object.keys(m).length,
    0
  );

  return (
    <AdminLayout>
      <div className="rbac-console">
        {/* ── command header ── */}
        <header className="rcx-header">
          <div className="rcx-header-row">
            <div>
              <p className="rcx-eyebrow">Access Control · Role Matrix</p>
              <h1 className="rcx-title">Role&nbsp;&amp;&nbsp;Permissions</h1>
              <p className="rcx-sub">
                Configure default permission grants per role. The System Administrator column is
                always full-access and is locked.
              </p>
            </div>
            <div className="rcx-header-aside">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.7rem' }}>
                {pendingCount > 0 && (
                  <span className="rcx-pill">
                    {pendingCount} unsaved
                  </span>
                )}
                <button
                  type="button"
                  className="rcx-btn rcx-btn--primary"
                  onClick={handleSave}
                  disabled={!hasPendingChanges || saving}
                >
                  {saving ? 'Saving…' : 'Save Changes'}
                </button>
              </div>
              <div className="rcx-legend">
                {ROLE_COLUMNS.map((r) => (
                  <span key={r.id} className="rcx-legend-chip">
                    <span className="rcx-legend-code" style={{ '--rc-chip': r.accent, background: r.accent }}>{r.label}</span>
                    {r.name}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </header>

        {loading && <div className="rcx-state">Loading permission matrix<span className="rcx-loading-dots" /></div>}
        {error   && <div className="rcx-state rcx-state--error">Error: {error}</div>}

        {!loading && !error && (
          <>
            <div className="rcx-matrix-wrap">
              <table className="rcx-matrix">
                <thead>
                  <tr>
                    <th>Feature / Action</th>
                    {ROLE_COLUMNS.map((r) => (
                      <th key={r.id} className="rcx-rolehead">
                        <span className="rcx-rolecode" style={{ '--rc-chip': r.accent, background: r.accent }}>{r.label}</span>
                        <span className="rcx-rolename" title={r.name}>{r.name}</span>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {groups.map((group) => (
                    <React.Fragment key={group.label}>
                      <tr className="rcx-group">
                        <td colSpan={ROLE_COLUMNS.length + 1}>{group.label}</td>
                      </tr>

                      {group.features.map((feat) =>
                        ACTIONS.filter((a) => feat.actions[a] != null).map((action) => {
                          const permId = feat.actions[action];
                          return (
                            <tr className="rcx-row" key={`${feat.feature}.${action}`}>
                              <td className="rcx-feat">
                                <span className="rcx-feat-name">{feat.title}</span>
                                <span className="rcx-action-tag">{ACTION_META[action]?.label || action}</span>
                              </td>
                              {ROLE_COLUMNS.map((r) => {
                                const checked = isGranted(r.id, permId);
                                const isAdmin = r.id === ADMIN_ROLE_ID;
                                const isDirty = !isAdmin && pending[r.id] && permId in pending[r.id];
                                return (
                                  <td key={r.id} className={`rcx-cell${isAdmin ? ' rcx-cell--admin' : ''}`}>
                                    <button
                                      type="button"
                                      className={`rcx-toggle${checked ? ' is-on' : ''}${isAdmin ? ' is-locked' : ''}${isDirty ? ' is-dirty' : ''}`}
                                      style={{ '--rc-chip': r.accent }}
                                      disabled={isAdmin}
                                      aria-pressed={checked}
                                      onClick={() => toggle(r.id, permId)}
                                      title={isAdmin
                                        ? 'System Administrator always has full access'
                                        : `${r.name}: ${ACTION_META[action]?.desc || action}`}
                                    >
                                      {(checked || isAdmin) && <CheckGlyph />}
                                    </button>
                                  </td>
                                );
                              })}
                            </tr>
                          );
                        })
                      )}
                    </React.Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
